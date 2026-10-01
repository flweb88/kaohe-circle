# 考核圈 · MVP-7 私信与通知 · 实施契约（v3.7.0）

> 本文档是 MVP-7 开工后的**唯一实施依据**。后端与前端并行开发时，所有命名、字段、接口、错误码、前端结构以本文为准。
> 蓝本：[考核圈-MVP7-消息私信与通知.md](./考核圈-MVP7-消息私信与通知.md)（下称"蓝本"）；总览：[考核圈-项目总览与开发计划.md](./考核圈-项目总览与开发计划.md)（下称"总览"）；X 规格：[规格文档/X用户关系网消息私信通知结构逻辑.md](./规格文档/X用户关系网消息私信通知结构逻辑.md)（下称"X§xx"）。
> 本契约**只读分析**既有代码产出，不修改任何现有文件；唯一写入即本文档。

---

## 1. 开工定稿决策表

| # | 待决策项 | 终稿结论 | 依据与理由 |
|---|---|---|---|
| D1 | 私信数据文件命名 | **保留 `kaohe-data/dms.php`**（不叫 conversations.php） | 蓝本 §3、总览 §9.2 已规划 `dms.php`；线上无双份文件；短名与 posts.php/social.php/follows.php/notify.php 风格一致 |
| D2 | 四段记录（conversations/members/messages/state）是否拆多文件 | **四段全部并入同一个 `dms.php`**，顶层键 `{conversations:[], conversation_members:[], messages:[], message_user_state:[]}` | 私信所有写操作（发消息/标记已读/本地删除/会话列表）都要联合读改这四段；单文件 = `db_transact_json` 一把锁即可原子完成；拆 4 文件需 `db_transact_multi` 4 把锁，并发写放大且增加死锁面。MVP-7 用户量级 <100，单文件 JSON 完全够用；未来若消息量爆炸再按 conversation_id 分片（本期不做） |
| D3 | 通知数据文件命名 | **保留 `kaohe-data/notify.php`**，不新建 `notifications.php` | 线上已有 55 条真实通知数据；总览 §9.2/§16 明确"命名唯一、无双份"；蓝本 §3 虽建议 notifications.php，但与线上命名冲突时以"零迁移"优先 |
| D4 | 通知 schema 是否扩字段 | **保持现有字段集 `{id,to,type,by,postId,text,ts,read}`，零扩列**；type 枚举新增 `dm`（`system` 预留，本期不产生）；`postId` 字段对 type=dm 时语义复用为 `conversation_id` | 老数据零迁移；前端按 `type==='dm'` 解释 `postId` 为会话 id；不引入 recipient_id/actor_id/event_type 等新字段（X§22-23 的命名与现有 to/by/type 一一对应，仅别名，不改写） |
| D5 | 新 API 文件命名 | 新建 `kaohe-api/dms.php` + `kaohe-api/notifications.php`；不动 social.php | dms.php 承载私信全部动作；notifications.php 承载通知中心全部动作 + unread 聚合（badge 数据源）；social.php 旧 notify/notify_read/unread 保留废弃（见 §3.7） |
| D6 | 消息撤回/删除语义 | **= 本地删除**：写 `message_user_state[{message_id,user_id,visible:false}]`，仅对执行者隐藏；物理消息保留在 messages 表，对方仍可见；**不做"撤回"**（无双方擦除语义） | 蓝本 §5、X§38、总览 §2.4；冻结口径已拍板"本地删除≠全局删除" |
| D7 | Block 后历史会话边界 | **can_interact 阻断"新发消息"**（send 动作 403 err:blocked）；**历史会话与历史消息保留可查看**（会话列表/消息列表不调 can_interact，只按 conversation_members 成员关系鉴权）；但聊天窗在 can_interact=false 时不渲染输入框与发送按钮 | 总览 §2.4"公开帖对已拉黑者仍可见但不可互动"——私信同理：历史可见、新发阻断。若 Block 后连历史都不可见，用户会误以为数据丢失，且与"本地删除≠全局删除"模型冲突 |
| D8 | 通知保留期 | **90 天**；惰性清理：在 notifications.php 的任何写动作（list 只读不清、mark_read 写时顺带清）触发时，过滤掉 `ts < now-90天` 的条目；不做 cron | 蓝本 §5"至少 90 天"；总览 §9.4"清理任务可预览可审计"；文件式存储无定时任务，惰性清理零运维 |
| D9 | 私信媒体 | **本期纯文字**；messages 表 `attachments` 字段保留为 `[]`（空数组占位），不接 media.php 上传管线 | 蓝本 §5；硬约束"媒体无关本 MVP"；字段预留便于未来 MVP-8 扩展 |
| D10 | DM Request / Snooze / 群聊 / E2EE / 通知聚合 / Protected/List/Subscribe | **全部不纳入** | 蓝本 §8 映射表 + 冻结口径；记录为未来选项，不预留 UI |
| D11 | dm 通知是否每次发消息都写 | **每条消息都写一条 dm 通知**，但按 `(to,type=dm,by,postId=conversation_id)` 未读去重：同会话已有未读 dm 通知时不新增，只更新 `text` 为最新消息前 20 字摘要 + 更新 `ts` | 复用 add_notify 既有去重风格（social.php:95-100）；通知中心里每个未读会话只占一条，不刷屏；用户点开会话后 mark_read 时把这条 dm 通知置 read=true |
| D12 | dm 通知的过滤规则 | 发消息前 `can_interact(me, peer)` 已挡住互拉黑（403）；写 dm 通知时再查 `is_muted(recipient, sender)`——若接收方静音了发件人，**消息本身仍落库送达，但不写 dm 通知**（Mute=弱过滤，只滤通知不滤内容） | 复用 add_notify social.php:92 同款过滤链；与 Mute 语义一致（总览 §2.4"Mute 仅过滤时间线/通知，仍可互相关注、对方不知情"） |

---

## 2. 数据 schema 终稿

### 2.1 `kaohe-data/dms.php`（新建，守卫头+JSON）

文件头：`<?php http_response_code(403); exit; ?>` 后接 JSON（由 `db_write_json` 自动加 GUARD 前缀，common.php:24）。部署时手动 `ssh 'cat >'` 推 NAS，属主 `http:http`，权限 0664。

顶层结构（默认值 `DMS_DEFAULT`）：

```json
{
  "conversations": [
    {
      "id": "a1b2c3d4e5f60718",
      "type": "dm",
      "created_by": "m5qa01@test.local",
      "created_at": "2026-10-01 13:00:00",
      "updated_at": "2026-10-01 13:05:21"
    }
  ],
  "conversation_members": [
    {
      "conversation_id": "a1b2c3d4e5f60718",
      "user_id": "m5qa01@test.local",
      "joined_at": "2026-10-01 13:00:00",
      "left_at": null,
      "last_read_message_id": "f9e8d7c6b5a43210"
    }
  ],
  "messages": [
    {
      "id": "f9e8d7c6b5a43210",
      "conversation_id": "a1b2c3d4e5f60718",
      "sender_id": "m5qa02@test.local",
      "body": "你好，周末的考核表发我一下",
      "attachments": [],
      "reply_to": null,
      "created_at": "2026-10-01 13:05:21",
      "edited_at": null,
      "deleted_at": null
    }
  ],
  "message_user_state": [
    {
      "message_id": "f9e8d7c6b5a43210",
      "user_id": "m5qa01@test.local",
      "visible": false,
      "hidden_at": "2026-10-01 14:00:00",
      "read_at": "2026-10-01 13:06:00"
    }
  ]
}
```

**字段类型与约束：**

| 字段 | 类型 | 约束 | 说明 |
|---|---|---|---|
| conversations.id | string(16 hex) | 全局唯一 | `bin2hex(random_bytes(8))`，与 social.php `new_id()` 同款 |
| conversations.type | string | 固定 `"dm"` | 预留未来 group，本期只 dm |
| conversations.created_by | string(小写邮箱) | 非空 | 首条消息发起人 |
| conversations.created_at / updated_at | string(`Y-m-d H:i:s`) | now() 同款 | updated_at 在每条 send 时刷新为该消息 created_at |
| conversation_members.conversation_id | string | 逻辑外键→conversations.id | |
| conversation_members.user_id | string(小写邮箱) | 逻辑外键→users.email | |
| conversation_members.joined_at | string | 会话创建时写入双方 | |
| conversation_members.left_at | string\|null | 本期恒 null（无退出会话功能） | |
| conversation_members.last_read_message_id | string\|null | null=从未读 | 未读数推导依据 |
| messages.id | string(16 hex) | 全局唯一 | |
| messages.conversation_id | string | 逻辑外键 | |
| messages.sender_id | string(小写邮箱) | 非空 | |
| messages.body | string | trim 后 1..2000 字符 | 纯文字；换行保留 |
| messages.attachments | array | 本期恒 `[]` | 预留 |
| messages.reply_to | string\|null | 本期恒 null（消息回复功能未来做） | |
| messages.created_at | string | now() | |
| messages.edited_at | string\|null | 本期恒 null（无编辑功能） | |
| messages.deleted_at | string\|null | 本期恒 null（物理消息不删；删除走 message_user_state） | |
| message_user_state.message_id | string | 逻辑外键→messages.id | |
| message_user_state.user_id | string(小写邮箱) | | |
| message_user_state.visible | bool | false=本地删除（对该 user 隐藏） | 默认 true（不写记录即可见） |
| message_user_state.hidden_at | string\|null | visible=false 时写入 | |
| message_user_state.read_at | string\|null | 本期不用（已读由 last_read_message_id 推导）；字段保留对齐 X§37 | |

**逻辑唯一键：**
- 1:1 会话幂等：`open_or_create(email)` 时，在 conversation_members 里找两个成员都是 `{me, peer}` 且 `left_at=null` 的会话——即先收集所有含 me 的会话，再检查另一成员是否 peer。找到则复用，找不到才创建。
- message_user_state 无独立唯一键，按 `(message_id,user_id)` 语义唯一，写入时先 upsert（找到同键记录则改，否则 append）。

**未读数推导算法（不存冗余计数）：**

给定 me 与某会话 c：
1. 找 me 在 c 的 member 记录，取 `last_read_message_id`（记为 L，可能 null）。
2. 在 messages 里过滤 `conversation_id=c.id 且 sender_id≠me 且 deleted_at=null`。
3. 对每条消息 m：若 L 为 null → m 未读；否则比较 m.id 与 L——由于消息 id 由 `bin2hex(random_bytes(8))` 生成，**不可字典序比较**，改用"m 在 messages 数组中的下标 > L 在 messages 数组中的下标"判定（消息按 append 顺序即时序顺序）。
4. 再排除掉 message_user_state 里 `(message_id=m.id, user_id=me, visible=false)` 的消息（本地删除不影响未读计数——已删消息不算未读）。
5. 计数 = 上述结果。

`unread` 总数 = 我参与的所有会话的 unread_count 之和。

### 2.2 `kaohe-data/notify.php`（**保持线上结构，零迁移**）

现有结构不变：

```json
{
  "items": [
    {
      "id": "ea41beb5bde4b971",
      "to": "m5qa03@test.local",
      "type": "like",
      "by": "m5qa01@test.local",
      "postId": "57da4aa5c7d14a9a",
      "text": "赞了你的动态",
      "ts": "2026-09-30 12:18:12",
      "read": false
    }
  ]
}
```

**type 枚举终稿：** `like / mention / reply / repost / quote / follow / comment / dm`（`system` 预留，本期不产生）。
- 现有 7 种（like/mention/comment/reply/repost/quote/follow）由 social.php 既有 add_notify 调用产生，**不动**。
- 新增 `dm`：由 dms.php send 动作写入（见 §3.6）。
- `postId` 字段对 type=dm 时语义 = conversation_id；对其他 type 保持原义（帖子 id；follow 时为 null）。

**dm 通知写入示例：**

```json
{
  "id": "7c2f...",
  "to": "m5qa02@test.local",
  "type": "dm",
  "by": "m5qa01@test.local",
  "postId": "a1b2c3d4e5f60718",
  "text": "你好，周末的考核表发我一下",
  "ts": "2026-10-01 13:05:21",
  "read": false
}
```

**兼容策略：** 老数据（现有 55 条）无 type=dm，前端按 type 分支渲染即可，无需迁移脚本。

### 2.3 数据文件守卫与属主约定

- 所有 `.php` 数据文件均以 `<?php http_response_code(403); exit; ?>\n` 开头（common.php GUARD 常量，db_write_json 自动追加）。
- 属主 `http:http`，权限 0664（db_write_json 里 chmod 0664）。
- 部署：deploy.sh 只推 kaohe-api/*.php 与 kaohe.html，**不推 kaohe-data/**。**实际上线方式（2026-10-01）**：因 NAS `kaohe-data/` 属主 `http:http` 775、部署账号 flweb 无写权限，无法手动 `cat >` 推送；改为**由 PHP（http 身份）在首次写动作时经 db_write_json 自动创建** dms.php——自动带 GUARD 守卫头、chmod 0664、属主 http:http，与现有 users.php/posts.php/notify.php 产生方式一致。已线上核验：`-rw-rw-r-- http http` + `<?php http_response_code(403); exit; ?>` 头。源码库 `kaohe-data/dms.php` 仅作 schema 骨架参考（-f 入库）。
- **无需迁移脚本**：notify.php 结构不变；dms.php 全新空文件（默认值 `{conversations:[],conversation_members:[],messages:[],message_user_state:[]}`）。

---

## 3. API 契约（逐接口）

**通用约定（对齐现有风格）：**
- 全部 `POST`，`Content-Type: application/json`，action 走 query string（`dms.php?action=xxx`），token 在 JSON body。
- 未登录 → 401 `{ok:false, err:"auth", msg:"登录已失效，请重新登录"}`（require_login 统一）。
- 权限不足（互拉黑/被拉黑）→ 403 `{ok:false, err:"blocked", msg:"对方已限制互动"}`（与 social.php:293 一致）。
- 目标不存在 → 404 `{ok:false, err:"notfound", msg:"用户不存在"}`。
- 忙/锁超时 → `{ok:false, err:"busy", msg:"系统繁忙，请稍后重试"}`。
- 成功 → `{ok:true, ...}`。
- 分页：游标式，`before_id`（取早于该 id 的数据），`limit` 默认 20、上限 50。

### 3.1 `kaohe-api/dms.php` — 会话列表

- **action**: `conversations`
- **请求**: `{token}`
- **成功响应**:
```json
{
  "ok": true,
  "items": [
    {
      "conversation_id": "a1b2c3d4e5f60718",
      "peer": {"email": "m5qa02@test.local", "nickname": "乙", "avatar": "zodiac:ox"},
      "last_message": {"id": "f9e8d7c6b5a43210", "body": "你好，周末的考核表发我一下", "ts": "2026-10-01 13:05:21", "sender_id": "m5qa02@test.local"},
      "unread_count": 2,
      "updated_at": "2026-10-01 13:05:21",
      "can_send": true
    }
  ]
}
```
- 按 `updated_at` 倒序。`can_send=false` 表示与 peer 互拉黑（前端据此隐藏输入框）。空列表返回 `{ok:true, items:[]}`。
- 错误：401 auth。

### 3.2 `kaohe-api/dms.php` — 发起/获取会话（幂等）

- **action**: `open_or_create`
- **请求**: `{token, email}`（email=对方小写邮箱）
- **逻辑**: ① require_login；② 校验 email 格式与用户存在（404 notfound）；③ 拒绝自己给自己发（err:self）；④ `can_interact(me, peer)` 失败 → 403 err:blocked；⑤ 在 conversation_members 里找 me+peer 双方都是成员的现存会话 → 命中则返回既有；⑥ 否则在**单文件事务**里新建 conversation + 两条 member 记录。
- **成功响应**:
```json
{"ok": true, "conversation_id": "a1b2c3d4e5f60718", "created": false,
 "peer": {"email":"m5qa02@test.local","nickname":"乙","avatar":"zodiac:ox"}}
```
- 错误：401 / 404 notfound / err:self / 403 blocked / err:busy。

### 3.3 `kaohe-api/dms.php` — 发消息

- **action**: `send`
- **请求**: `{token, conversation_id, body}`
- **逻辑**:
  1. require_login；
  2. 校验 me 是该会话成员（不是 → 403 err:forbidden）；
  3. 查 peer（另一成员）；`can_interact(me, peer)` 失败 → 403 err:blocked；
  4. body trim 后空串 → 400 `{ok:false,err:"empty",msg:"消息不能为空"}`；>2000 字 → `{ok:false,err:"too_long",msg:"消息过长"}`；
  5. **单文件事务（db_transact_json DMS_FILE）**：append message、刷新 conversation.updated_at；
  6. **同一事务内**追加 dm 通知到 notify.php——注意此处跨文件，改用 `db_transact_multi([DMS_FILE, NOTIFY_FILE], ...)`：
     - 追加 message 与刷新 updated_at；
     - 写 dm 通知：若 `is_muted(peer, me)` 为真 → 不写通知（消息仍送达）；否则按 `(to=peer, type=dm, by=me, postId=conversation_id)` 查未读 dm 通知——命中则更新 text=最新消息前 20 字 + ts=now；未命中则 append 新通知。
- **成功响应**:
```json
{"ok": true, "message": {"id":"f9e8...","conversation_id":"a1b2...","sender_id":"m5qa01@test.local","body":"...","created_at":"2026-10-01 13:06:00"}}
```
- 错误：401 / 403 blocked / 403 forbidden（非成员）/ 400 empty / 400 too_long / err:busy。

### 3.4 `kaohe-api/dms.php` — 拉取消息（游标分页）

- **action**: `messages`
- **请求**: `{token, conversation_id, before_id?, limit?}`
- **逻辑**: require_login；校验 me 是成员（否则 403 forbidden）；过滤 message_user_state 里 `(message_id, me, visible=false)` 的消息；按 created_at 倒序取最新 limit 条；若 before_id 非空，则取该 id 之前的 limit 条（早于它的）。
- **成功响应**:
```json
{"ok": true, "items": [{"id":"...","sender_id":"...","body":"...","created_at":"...","mine":true}],
 "has_more": false}
```
- `mine` = (sender_id===me)，前端据此渲染左右气泡。
- 错误：401 / 403 forbidden / 404 notfound（会话不存在）。

### 3.5 `kaohe-api/dms.php` — 未读数 / 标记已读 / 本地删除

| action | 请求 | 成功响应 | 说明 |
|---|---|---|---|
| `unread` | `{token}` | `{"ok":true,"dm":3}` | 按 §2.1 算法汇总我所有会话的未读数 |
| `mark_read` | `{token, conversation_id, message_id?}` | `{"ok":true}` | 把 me 在该会话的 last_read_message_id 设为 message_id（缺省=该会话最新消息 id）；**同一事务内**把 notify.php 里 `(to=me, type=dm, postId=conversation_id, read=false)` 的通知全部置 read=true |
| `hide_message` | `{token, message_id}` | `{"ok":true}` | 在 message_user_state upsert `{message_id, user_id:me, visible:false, hidden_at:now}`；不校验 sender（删除本地可见性不需要是发送者，双方都能删自己视图里的消息） |

mark_read 跨 dms.php + notify.php，用 `db_transact_multi([DMS_FILE, NOTIFY_FILE])`。

### 3.6 `kaohe-api/notifications.php` — 通知中心

| action | 请求 | 成功响应 | 说明 |
|---|---|---|---|
| `list` | `{token, after_id?, limit?, type?}` | `{"ok":true,"items":[{id,type,by,postId,text,ts,read,byNickname,byAvatar,conversation_id}],"has_more":bool}` | 按 ts 倒序；type 可选过滤；conversation_id = (type==='dm' ? postId : null)；after_id 游标；加载时顺带惰性清理 ts>90 天前的条目（写操作才清，list 只读不清） |
| `unread` | `{token}` | `{"ok":true,"notify":2,"dm":1}` | **badge 数据源**：notify=notify.php 里 to=me 且 read=false **且 type!=='dm'** 条数（**v3.7.0 口径：notify 排除 type=dm，dm 未读由 dm 字段按会话算法统计，避免双计数**）；dm=dms.php 里我的未读私信总数（复用 §2.1 算法）。跨两文件读，单文件 db_read_json 各读一次即可（无写） |
| `mark_read` | `{token, id?}` | `{"ok":true}` | id 非空 → 单条已读（校验 to=me）；id 空/缺省/"all" → 全部已读。顺带惰性清理 90 天前条目 |

错误码对齐：401 auth / err:busy。list 空返回 `{ok:true,items:[],has_more:false}`。

### 3.7 与 social.php 旧 notify/notify_read/unread 的关系

- **social.php 三个旧动作（notify / notify_read / unread）后端代码保留不动**（硬约束禁止改 social.php），但**前端 v3.7.0 起全部切换到 notifications.php**。
- 现状：kaohe.html 当前**从未调用**这三个动作（badge-msgs 一直是静态 0），所以无旧前端兼容负担。
- 推荐方案：notifications.php 成为通知中心唯一新入口；social.php 旧动作标记为 deprecated（代码留着不删，未来 MVP-8 清理）。这样**通知写入侧**只有两处：① social.php add_notify（社交互动，like/mention/reply/...）；② dms.php send（dm 类型）。**通知读取侧**统一在 notifications.php，避免双份维护。
- `unread` 聚合：前端 badge 只调 `notifications.php?action=unread`，返回 `{notify, dm}` 两值，分别渲染两个红点，**不混 total**。

### 3.8 事件→通知接线点

见 §5 接线图。dm 通知由 dms.php send 动作在事务内写 notify.php（不调 social.php 的 add_notify，因为 social.php 是入口脚本不可被 require；内联同款过滤逻辑，约 10 行）。

---

## 4. 前端改造清单（kaohe.html）

> 原则：**不改现有社交/关注/拉黑/静音任何代码路径**，只替换 `view.tab==='msgs'` 的渲染分支、拆分 badge、新增消息相关 CSS/JS。

### 4.1 版本号更新（两处）

- kaohe.html:688 footer：`考核小管家 v3.6.0 · 考核圈社交版` → `v3.7.0`
- kaohe.html:1049 rk-ver：`考核圈 · 社交版第一页 v3.6.0` → `v3.7.0`

### 4.2 消息 tab 结构改造

- **现状**（kaohe.html:693）：`<button class="tb" data-tab="msgs">...消息<span class="badge" id="badge-msgs">0</span></button>`；view.tab==='msgs' 时 renderComingSoon。
- **改造后**：
  - 外层保留 data-tab="msgs"，进入后渲染双子 tab：「💬 私信」「🔔 通知」。
  - 内部状态：`view.msgsSubTab = 'dm' | 'notif'`（默认 dm）；`view.dm = { convId:null, list:[], cursor:null }`；`view.notif = { list:[], cursor:null }`。
  - 双子 tab 复用现有 `.tab / .tab.active` 样式（line 44-46），样式变量 `--primary/--primary-soft/--border/--muted` 直接复用。
  - 通知列表复用现有 `.notify-row / .ni / .nt / .nr-t`（line 372-377）样式；未读行加 `.unread`（已有 primary-soft 背景）。

### 4.3 私信 UI

- **会话列表页**：每条一行 = 头像（38px 圆形）+ 昵称（粗）+ 最新一条 body（截断 2 行）+ 右侧未读红点数字 + 时间（HH:mm）。空态："还没有私信，去用户主页发条私信吧"。
- **聊天窗**：
  - 顶部：返回箭头 + 对方头像/昵称（点击进对方主页）。
  - 中部：消息气泡，`mine=true` 靠右蓝底白字（`--primary`），否则靠左白底灰边；时间小字（`--muted`）。
  - 底部：输入框（textarea，单行回车发送、Shift+Enter 换行）+ 发送按钮（`btn-primary` 样式，line 83）。
  - can_send=false（互拉黑）时：底部输入框区替换为灰色提示"对方已限制互动"。
  - 回车发送：Enter 键监听（keydown）。
- **发起私信入口**：
  - 用户主页（renderUserProfile）操作区加"📩 私信"按钮 → 调 `dms.php?action=open_or_create` 拿 conversation_id → 切到 msgs tab 并打开聊天窗。
  - 评论/回复头像旁不加私信入口（避免 UI 拥挤）；MVP-7 只从用户主页/关注列表发起。

### 4.4 通知中心 UI

- 顶部右侧"全部已读"按钮（调用 notifications.php?action=mark_read 不传 id）。
- 列表：每行图标（按 type 区分：like=❤ / mention=@ / reply=💬 / repost=🔁 / quote=💬 / follow=➕ / dm=✉️）+ byNickname + text + 时间（HH:mm 或 MM-dd）。
- 点击行为：
  - type=like/mention/reply/repost/quote → 跳动态详情（view.postDetail = postId，切 circle tab）；同时调 mark_read 单条。
  - type=follow → 跳对方主页（view.tab='user-profile', email=by）；mark_read。
  - type=dm → 切 msgs tab + dm 子 tab + 打开该会话（conversation_id=postId）；mark_read（dms.php mark_read 会自动把这条 dm 通知置已读）。
- 未读行高亮 `.unread`（primary-soft 背景，已有样式）。

### 4.5 未读徽标拆分

- **现状**：`<span class="badge" id="badge-msgs">0</span>` 单红点。
- **改造**：消息 tab 按钮内拆两个红点：
  - `<span class="badge badge-dm" id="badge-dm" style="display:none">0</span>`（私信未读，蓝点）
  - `<span class="badge badge-notif" id="badge-notif" style="display:none">0</span>`（通知未读，红点/橙点）
  - 计数为 0 时 badge 隐藏（display:none）；>0 时显示数字（>99 显示 "99+"）。
- **数据来源**：登录后 / 每 30s 轮询 / 切 tab 时调 `notifications.php?action=unread`，拿 `{notify, dm}` 两值分别填。**不混 total**。
- **dm 行与通知角标解耦（v3.7.0 口径）**：通知中心列表里的 type=dm 行**不计入通知角标 badge-notif**（其未读由 badge-dm 按会话算法统计）、**不对 dm 行做未读高亮**（未读高亮仅用于社交类通知 like/mention/reply/repost/quote/follow）。

### 4.6 三端断点布局

复用现有响应式（现有 480/768/1280 三档断点已存在，不新增）：
- **≤480（手机）**：双子 tab 顶栏横排；会话列表全宽；聊天窗全屏（顶部返回）；通知行头像 38px、文字 13.5px。
- **481-768（大屏手机/小平板）**：聊天窗气泡最大宽度 70%。
- **≥1280（桌面）**：消息页左右分栏——左 320px 会话列表，右 flex 聊天窗；通知中心最大宽度 720px 居中。

### 4.7 前端 API 调用封装

- 全部走现有 `postJSON(url, data)`（kaohe.html:915）。
- 新调用样例：
  - `postJSON('kaohe-api/dms.php?action=conversations', {token:Auth.token})`
  - `postJSON('kaohe-api/notifications.php?action=unread', {token:Auth.token})`
- 401 走现有 apiFail 处理。

---

## 5. 事件→通知接线图

| 触发动作 | 落库位置（文件+函数/action） | 事件类型 type | 通知接收者 | 过滤规则 | 去重方式 |
|---|---|---|---|---|---|
| 点赞帖子 | social.php:572 `action=like` → `add_notify($p['author'],'like',$meEmail,$id,'赞了你的动态')` | like | 帖子作者 | add_notify 内 has_blocked/blocked_by/is_muted 过滤（social.php:92） | 同 (to,type,by,postId) 未读已存在则不写（social.php:95-100） |
| 点赞回复 | social.php:699 `action=comment_like` → `add_notify($author,'like',$meEmail,$cid,'赞了你的回复')` | like | 回复作者 | 同上 | 同上 |
| 回复帖子 | social.php:653 `action=reply` → `add_notify($parentPost['author'],'reply',...)` | reply | 父帖作者 | 同上 | 同上 |
| 发帖@提及 | social.php:475 `action=post` → `add_notify($ee,'mention',...)` | mention | 被@用户 | 同上 | 同上 |
| 回复@提及 | social.php:654 `action=reply` → `add_notify($ee,'mention',...)` | mention | 被@用户 | 同上 | 同上 |
| 引用帖 | social.php:744 `action=quote` → `add_notify($qc['author'],'quote',...)` | quote | 被引用帖作者 | 同上 | 同上 |
| 纯转发 | social.php:763 `action=repost` → `add_notify($orig['author'],'repost',...)` | repost | 原帖作者 | 同上 | 同上 |
| 关注 | social.php:822 `action=follow` → `add_notify($target,'follow',...,null,'关注了你')` | follow | 被关注者 | 同上 | 同上（postId=null） |
| **发私信**（新增） | **dms.php send 动作**（db_transact_multi 内联写 notify.php） | **dm** | **peer（接收方）** | **① can_interact 在 send 前已挡互拉黑（403）；② is_muted(peer,me) → 不写通知（消息仍送达）** | **同 (to,type=dm,by,postId=conversation_id) 未读已存在则更新 text+ts，不新增** |

---

## 6. 验收用例矩阵

> 测试副本 `/tmp/kaohe-e2e/`，PHP `/private/tmp/php -S 127.0.0.1:8090`。token 映射：A=m5qa01@test.local, B=m5qa02@test.local, C=m5qa03@test.local, D=m5qa04@test.local。POST 格式 `{base}/kaohe-api/{file}?action={action}`。

| # | 对应蓝本§6 | 前置条件 | 操作步骤 | 断言 | 预期响应 |
|---|---|---|---|---|---|
| T1 | §6.1 双人互发 | A、B 已注册登录 | ① A 调 `dms.php?action=open_or_create {email:B}` → 拿 conversation_id=CID1；② A 调 `send {conversation_id:CID1, body:"你好A→B"}`；③ B 调 `conversations` | A 创建返回 `{ok:true, conversation_id:CID1, created:true}`；B 的会话列表出现 CID1，peer=A | items[0].conversation_id=CID1, peer.email=A, last_message.body="你好A→B" |
| T2 | §6.1 会话列表未读数 | 同 T1 后 B 未读 | ① B 调 `unread`；② B 调 `conversations` | B 的 dm 未读数=1；会话列表 unread_count=1 | `{ok:true,dm:1}`；items[0].unread_count=1 |
| T3 | §6.1 已读标记 | 同 T2 后 | ① B 调 `mark_read {conversation_id:CID1}`；② B 再调 `unread` | B 的 dm 未读数归 0；会话 unread_count=0；notify.php 里 type=dm/postId=CID1 的未读通知被置 read=true | `{ok:true}`；再查 `{dm:0}` |
| T4 | §6.1 未读分离 | A、B、C 各自有未读 | ① B 收到 A 的私信（dm 未读+1）；② B 被 C 点赞（notify 未读+1）；③ B 调 `notifications.php?action=unread` | 两个计数独立：dm=1, notify=1，不混 total | `{ok:true,notify:1,dm:1}`（**notify 排除 type=dm 口径：那 1 条 dm 通知不计入 notify，notify=1 来自 C 的点赞**）；前端两个红点分别亮 |
| T5 | §6.2 通知各类触发 | A、B、C | ① B 点赞 A 的帖子；② B 在 A 帖下回复；③ B 转发 A 帖；④ B 引用 A 帖；⑤ B 关注 A；⑥ B @A | A 的通知列表含 5 条（like/reply/repost/quote/follow/mention），type 字段正确；同一互动只产生一条（重复点赞不新增） | list items 长度正确，type 枚举正确；重复 like 不新增 |
| T6 | §6.2 同一互动一条通知 | A 对 B 同一点赞 | A 连续两次 `like` 同一帖 | B 的 notify.php 里只新增一条 type=like 未读 | 第二次 add_notify 命中去重（social.php:95-100），不 append |
| T7 | §6.3 Block 后无通知 | A 拉黑 B | ① A block B；② B 点赞 A 的帖子；③ B 给 A 发私信 | ② B 的 like 请求 403；③ B 的 send 请求 403；A 的 notify.php 无新增 | ② `{ok:false,err:"blocked",msg:"对方已限制互动"}` HTTP403；③ 同；A unread 不变 |
| T8 | §6.3 Block 后历史会话可见 | A、B 已有 CID1 互发过；A block B | ① B 调 `conversations`；② B 调 `messages {conversation_id:CID1}`；③ B 调 `send` | ① 会话仍在列表（can_send=false）；② 历史消息可见；③ send 403 | items 含 CID1 且 can_send=false；messages 返回历史；send 403 blocked |
| T9 | §6.4 本地删除对方仍可见 | A、B 在 CID1；A 发了消息 M1 | ① A 调 `hide_message {message_id:M1}`；② A 调 `messages {CID1}`；③ B 调 `messages {CID1}` | ① ok；② A 看不到 M1；③ B 仍能看到 M1 | A 的 items 无 M1；B 的 items 含 M1 |
| T10 | §6.5 从用户页发起私信 | A 登录 | ① A 访问 B 的 user_profile；② 点"📩 私信"按钮 → open_or_create；③ 进入聊天窗发消息 | open_or_create 幂等：第一次 created=true，第二次 created=false（同 CID）；消息送达 B | created 字段第一次 true、第二次 false；B unread +1 |
| T11 | §6.1 空 body 拒绝 | A 在 CID1 | A 调 `send {body:"   "}` | 400 err:empty | `{ok:false,err:"empty",msg:"消息不能为空"}` |
| T12 | §6.1 非成员访问 | C 登录 | C 调 `messages {conversation_id:CID1}`（C 不是成员） | 403 err:forbidden | `{ok:false,err:"forbidden",msg:"无权访问该会话"}` |
| T13 | §6.6 三端布局 | — | 分别以 480/768/1280 宽度打开消息页 | 手机端会话列表全宽、桌面端左右分栏；气泡不溢出；badge 红点正确拆分 | 无横向滚动；badge-dm/badge-notif 独立 |
| T14 | §6.6 版本号 | — | 打开页面 footer 与 rk-ver | 显示 v3.7.0 | 两处文案均为 v3.7.0 |
| T15 | 静音不滤消息只滤通知 | A 静音 B | ① A mute B；② B 给 A 发私信 M2；③ A 调 `messages`；④ A 调 `notifications.php?action=list` | ③ A 能看到 M2（消息送达）；④ 通知列表里无 type=dm 新增（is_muted 过滤） | messages items 含 M2；list 里无 B→A 的 dm 通知 |
| T16 | dm 通知去重聚合 | A、B 在 CID1；B 未读 | ① A 连发 3 条消息；② B 查 notify.php | 通知中心 type=dm 的未读通知只有 1 条，text=第 3 条消息摘要，ts=第 3 条时间 | 3 次 send 只产生 1 条未读 dm 通知（更新 text/ts，不 append） |
| T17 | 全部已读 | B 有 3 条未读通知 | B 调 `notifications.php?action=mark_read`（不传 id） | 3 条全部 read=true；unread.notify=0 | `{ok:true}`；再查 unread notify=0 |

---

## 附：硬约束自检

- [x] 冻结口径：三层模型 + message_user_state；unread 分离；所有注册用户互发（无 DM Request）；Event≠Notification；无 Protected/List/Subscribe/群聊/E2EE/Snooze/聚合——均已在 §1 定稿。
- [x] 复用：db_transact_json / db_transact_multi（D3/D5）、can_interact（§3.3/§5）、auth.php require_login（全接口）、add_notify 过滤链（§3.6/§5 内联同款）、follows/blocks/mutes 边数据。
- [x] 版本 v3.7.0；数据文件 PHP 守卫头 + JSON（§2.3）。
- [x] 媒体无关：attachments 恒 []（§2.1）。
- [x] 不修改任何现有文件：唯一写入即本文档。deploy.sh 的 PHP_FILES 白名单需在开发阶段追加 `dms.php notifications.php`（开发时改 deploy.sh 不属于本契约范围，由实施者执行）。
- [x] 字段示例用小写邮箱 m5qa01@test.local。
