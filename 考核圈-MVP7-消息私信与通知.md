# 考核圈 · MVP-7 消息私信与通知（v3.7.0）

## 上下文（Context）

- **所属项目**：考核圈（考核小管家社交版）——总览与完整目录见 [考核圈-项目总览与开发计划.md](./考核圈-项目总览与开发计划.md)
- **前置依赖**：[MVP-5 动态核心](./考核圈-MVP5-动态核心.md)（v3.5.5 已上线：回复帖/纯转发/引用/Thread 对齐，通知类型 mention/like/reply/repost/quote/follow）、[MVP-6 用户关系网](./考核圈-MVP6-用户关系网.md)（v3.6.0：关注/Block/Mute 数据与能力判断）
- **对齐规格**：X 用户关系/消息/通知工程规格——[规格文档/X用户关系网消息私信通知结构逻辑.md](./规格文档/X用户关系网消息私信通知结构逻辑.md)（本文 §27-38 消息、§22-26 通知、§59-65 数据、§68 事件语义引用其节号）
- **后续衔接**：[MVP-8 增强打磨](./考核圈-MVP8-增强打磨.md)
- **状态**：📋 待排期（未开工，无代码产出）

---

## 1. 目标

✉️消息 tab 由占位变为真实功能：私信（1 对 1）+ 通知中心。

用户决策要点：
- 私信：**所有注册用户之间均可互发**；1 对 1
- 提及（评论、点赞、分享）与通知联动
- 关系/消息/通知按 **Event ≠ Notification** 分层建模（X 规格 §68）：事件是事实，通知是系统决定告诉谁

## 2. 页面

- **✉️消息 tab** = 两个子 tab：「私信」「通知」（未读数红点/徽标**分开维护**，不混为一个 total_unread，X 规格 §71-72）
- **私信**：会话列表（对方头像/昵称/最新一条/未读数）→ 聊天窗（气泡、时间、输入框；支持发文字；可选带媒体）；所有注册用户可互发（从用户主页/关注列表/评论头像发起）
- **通知中心**：@提及、点赞、回复、转发（纯转发）、引用、新关注（MVP-7 可扩 `dm`）分类型列表（图标+昵称+动作+时间）；点击跳转（回帖/回用户页）；未读→已读；全部已读按钮

## 3. 数据模型（建议基线，MVP-7 开工时定稿）

**私信——三层模型（Conversation / Member / Message，对齐 X 规格 §27-30、§59-62）**

- `kaohe-data/dms.php`（或 `conversations.php`，命名 MVP-7 开工定稿）：
  - `conversations`: `[{ id, type:"dm", created_by, created_at, updated_at }]`
  - `conversation_members`: `[{ conversation_id, user_id, joined_at, left_at, last_read_message_id, notification_snoozed_until }]`（**成员独立建模，不把参与者写死在消息里**）
  - `messages`: `[{ id, conversation_id, sender_id, body, attachments[], reply_to, created_at, edited_at, deleted_at }]`
- **用户本地删除 ≠ 全局删除**（X 规格 §38）：`message_user_state: [{ message_id, user_id, visible, read_at }]`（并入 dms.php 或独立）；删除只对执行者隐藏，对方仍可见
- 未读数由 `last_read_message_id` 推导，不存冗余计数（或维护时保证写入一致性）

**通知——NotificationEvent（对齐 X 规格 §22-23、§64-65）**

- `kaohe-data/notifications.php`（与线上 `notify.php` 命名统一后定稿，避免双份）：`[{ id, recipient_id, actor_id, event_type, target_type, target_id, conversation_id, created_at, read_at, metadata }]`
- type 枚举（v3.5.5 已对齐）：`mention / like / reply / repost（纯转发） / quote（被引用） / follow`；MVP-7 扩展：`dm / dm_request（若引入 Request）/ system`
- **Mention ≠ Reply**（X 规格）：@ 开头普通发帖只是 Post+Mention，不构成回复通知
- **通知主表不写推送状态**；如未来需多渠道（Push/Email），独立 `notification_deliveries`（§65）
- 建议字段基线见总览 §9.2；去重（同一互动事件只生成一条）、接收者隔离、已读时间

## 4. 后端接口

- `kaohe-api/dms.php`：会话列表 / 发起会话 / 发消息 / 拉取消息（分页）/ 未读数 / 已读标记 / 本地删除（可选，见 §5）
- **事件 → 通知**：MVP-5 互动与 MVP-6 关注动作落库产生事件（`post.liked` / `follow.created` 等，总览 §6.3），通知由策略层消费（**Block/Mute 过滤 → 偏好 → 落通知**，X 规格 §68/§26）；同一请求内函数完成副作用，部分失败需补偿（见 §5）
- 通知读取：列表 / 未读数 / 标记已读 / 全部已读
- 能力判断联动：DM 权限按总览 §2.4 顺序（Block 优先），复用 MVP-6 `can_interact`

## 5. 待决策项（开工时冻结，不得默认为已有功能）

- **DM Request（待接受消息）**：现有决策"所有注册用户可互发"下**默认不引入**（X 规格 §31-32 生命周期记录为未来选项，若未来限制陌生人私信再启用）
- 消息撤回 / 删除会话语义（建议：撤回仅隐藏本人展示；会话删除=本地删除，全局不删——按 §3 message_user_state）
- 拉黑后的私信/通知阻断（MVP-6 Block 纳入后自动生效；开工确认边界：已存在的历史会话是否保留可查看）
- 通知保留期与清理规则（数据生命周期，见总览 §9.4；建议至少 90 天，MVP-7 开工定稿）
- 私信是否支持媒体（本期可选发文字；媒体待确认——上传/压缩复用 MVP-5 media 管线）
- 多文件写入非事务的部分失败处理（事件写入成功但通知失败）——补偿/重试机制（事件 ID 幂等，总览 §6.3）
- 通知聚合展示（"Alice 等 3 人赞了"，X 规格 §69-70）——建议 MVP-8
- Snooze（会话通知暂停，§39）——暂不纳入，记录为未来选项

## 6. 验收标准

1. 双人互发私信、会话列表、未读数、已读标记可用；**未读私信与未读通知红点分开**
2. 通知各类（提及/点赞/评论/转发/新关注，MVP-7 可扩 dm）正确触发与已读、红点；**同一互动只产生一条通知**
3. **Event≠Notification**：Block/Mute 用户后的互动不产生通知（联动 MVP-6）
4. 本地删除消息后对方仍可见（若纳入删除）
5. 与 MVP-5/6 联动（从帖子/用户页发起私信）
6. 三端布局正常；版本 v3.7.0；git 双库可回滚；**复核通过后上线（2026-10-01 起用户授权）**

## 7. 维护与更新规则

- 本 MVP 范围有任何改动，同步更新本文档并更新总览第 3/7/9/10 节。
- 完成上线后：状态 → ✅ 已上线，并在总览第 3.1 节版本表登记。

## 8. X 规格采纳 / 暂不纳入映射

| X 规格能力 | 本节 | 本期 MVP-7 | 说明 |
|---|---|---|---|
| Conversation / Member / Message 三层 | §27-30 | ✅ 采纳 | 成员独立建模 |
| DM Request 生命周期 | §31-32 | ❌ 默认不引入 | 现有决策"所有注册用户可互发"；记录为未来选项 |
| DM 权限矩阵 / DM Block | §33-34 | ✅ 采纳（DM Block 并入 MVP-6 Block） | 能力判断顺序见总览 §2.4 |
| Message 模型（含 attachments/reply_to/edited_at） | §35 | ✅ 采纳 | 开工定稿 |
| Read Receipt（sent/delivered/read） | §37 | 🟡 可选 | 未读由 last_read_message_id 推导为先 |
| 用户本地删除 ≠ 全局删除 | §38 | ✅ 采纳 | message_user_state |
| Snooze（会话通知暂停） | §39 | ❌ 暂不纳入 | 记录为未来选项 |
| Event ≠ Notification | §68 | ✅ 采纳 | 通知主表不写推送状态 |
| 事件类型枚举 | §23 | ✅ 采纳 | 扩展 dm/dm_request/system |
| 通知过滤链（Block/Mute→Preference→Channel） | §26 | ✅ 采纳 | MVP-6 Block/Mute 联动 |
| 通知聚合展示 | §69-70 | ❌ 暂不纳入 | 建议 MVP-8 |
| 未读/Badge 分离 | §71-72 | ✅ 采纳 | unread_notifications / unread_messages 分开 |
| 群聊（Group DM） | §29 | ❌ 暂不纳入 | 本产品 1:1 |
| 加密 Chat（E2EE） | §44 | ❌ 暂不纳入 | 非本期目标 |
