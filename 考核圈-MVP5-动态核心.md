# 考核圈 · MVP-5 动态核心（v3.5.0）

## 上下文（Context）

- **所属项目**：考核圈（考核小管家社交版）——总览与完整目录见 [考核圈-项目总览与开发计划.md](./考核圈-项目总览与开发计划.md)
- **前置依赖**：MVP-1 登录注册（v3.1.0 ✅）、MVP-2 首页蓝色主题+四tab导航（v3.2.0 ✅）、MVP-3 个人中心（v3.3.0 ✅）、MVP-4 首页卡片化（v3.4.0 ⏳ 待部署）；follow.php 关注落库已上线
- **后续衔接**：[MVP-6 用户关系网](./考核圈-MVP6-用户关系网.md)（@跳转/用户主页）、[MVP-7 消息私信与通知](./考核圈-MVP7-消息私信与通知.md)（通知触发）、[MVP-8 增强打磨](./考核圈-MVP8-增强打磨.md) 复用
- **状态**：⏳ **待部署（开发+自测完成）**——v3.5.0 动态核心已实现并通过 54 条验收矩阵（明细 `artifacts/复核包-MVP5-v3.5.0.md`）；停在待部署，未经用户授权不部署；git 提交号待回填

---

## 1. 目标

底部导航「⚡动态」tab 由占位页变为真实动态页，完成“创建内容—进入动态流—互动—刷新/重新进入后数据仍一致”的闭环。重点不是一次实现复杂社交算法，而是建立稳定的数据与交互基础，为 MVP-6/7 提供可复用能力。

用户决策要点（2026-09-30 确认）：
- 推荐流为**伪随机推送**机制（模样像 X 的 For You，先以随机推送实现）
- 发帖支持**万事万物**：文字、图片、视频、音频（暂不支持直播）
- 发帖时可选**回复者范围**：全部 / 我关注的账号 / 我提及的
- 转发为**简单转发**；分享内容设点赞、评论、转发、书签
- UI 参考 X：白底卡片、头像+昵称+时间、操作条 💬评论/🔁转发/❤️点赞/🔖书签

## 2. 既有基础：social.php 雏形（重要事实，开工必读）

源码库 `kaohe-api/social.php` 自 v3.1.0 提交（`b08ba62`）起存在，**从未部署到 NAS**（deploy.sh 白名单不含）。

- 已实现动作：`staff_card`(无鉴权) / `feed` / `post` / `del` / `like` / `unlike` / `comment` / `repost` / `bookmark` / `unbookmark` / `follow` / `unfollow` / `following` / `followers` / `posts_of` / `notify` / `notify_read` / `unread`。
- 数据文件设计：`feed.php` / `follows.php` / `notify.php` / `bookmarks.php`（与本文 §4 规划的 `posts.php` / `social.php` 命名**不同**）。
- 未经验证：并发写、鉴权完备性、媒体处理均未实装。

**恢复开发第一步：审阅该文件，决定复用/整合或废弃，统一数据文件命名，避免双份数据文件。**

## 3. 页面

- **动态页** = 顶部两个子 tab：「推荐」「关注」（激活蓝色，类 X）
  - 「推荐」：全站有效帖，**伪随机推送**——取全站有效帖后随机打乱/加权混排；每次下拉刷新重新拉取一批（同批去重）
  - 「关注」：按关注关系（follows）过滤的帖子，时间序（关系数据未就绪时定义降级行为，如显示空态+引导关注）
- **发帖入口**：动态页顶部发帖框（头像 + 占位文字"发点什么…"）→ 点开弹出发帖面板
  - 文字内容（必填或至少有一项媒体/成绩卡）；字数计数；重复提交防护
  - 媒体上传：图片/视频/音频，单帖多文件（建议上限 4 个）；预览与移除
  - 打卡成绩分享卡（可选）：从我的打卡记录里选一条生成卡片——考核名 / 周期 / 成绩 / 奖励分钟（用户主动选择，不暴露完整账本）
  - @提及：输入 @ 弹出用户搜索选择，可多选；正文中 @用户名 高亮
  - **回复范围**：全部 / 我关注的账号 / 我提及的（单选，存储 reply_policy）
  - 蓝色「发帖」按钮
- **帖子卡片（X 风）**：头像 + 昵称 + 时间 + 回复范围小标 → 内容文字（@高亮，安全转义）→ 媒体（图片多图网格/视频播放器/音频播放器）→ 打卡成绩卡 → 操作条 💬评论 / 🔁转发 / ❤️点赞（点亮红色）/ 🔖书签
- **评论**：点💬展开评论列表 + 输入框（可 @提及）；评论条：头像/昵称/时间/文字
- **转发**：简单转发（原帖进入我的关注流；转发者动态展示"转发了 @原帖"）
- **删除**：本人可删自己的帖子/评论（软删除，保留记录）

## 4. 数据模型（已定稿，v3.5.0 实施口径）

> 命名二选一已定稿，**严禁双份数据文件**：帖子主体用 `posts.php`（不用 feed.php）；互动记录外置到 `social.php`（不再内联进帖子）；关注边唯一 canonical 为 `follows.php`（follow.php 注册后关注 admin 改落此文件，废弃每用户文件 `data.followed`）。社交数据一律不进每用户 `{邮箱}.php`。

| 文件 | 结构 | 说明 |
|---|---|---|
| `posts.php` | `{ items:[{ id, author, type, text, media_ids[], share_card, mentions[], reply_policy, repost_of, createdAt, status:"normal"\|"deleted", schema_v:1 }], reqs:{client_req_id:postId} }` | 帖子主体 |
| `social.php` | `{ likes:[{post_id,user,at}], comments:[{post_id,id,user,text,mentions[],reply_to,at,status}], reposts:[{post_id,user,at}], bookmarks:[{post_id,user,at}] }` | 互动记录（唯一键 post_id+user；计数全部由此 count 推导，前端不自报） |
| `follows.php` | `{ <follower>:[<followee>...] }` | 关注边 |
| `notify.php` | `{ items:[{ id,to,type,by,postId,text,ts,read }] }` | 通知 |
| `uploads/manifest.php` | `{ items:{ <id>:{ id,uploader,kind,mime,size,status:"pending"\|"ready"\|"failed",createdAt,readyAt,files:{orig,sm,md,video720} } } }` | 媒体状态清单 |

> 全部沿用「PHP 守卫头（`<?php exit; ?>`）+ JSON」；所有写动作走 common.php 新增的 `db_transact_json`（flock 事务临界区）。

## 5. 后端接口（已落地，v3.5.0 实施口径）

统一包裹 `{ok:true,...}` / `{ok:false,err,msg}`；除媒体上传为 multipart 外一律 POST+JSON body，action 走 query，token 放 body（raw 回放允许 GET `?token=`）。共 13 组动作：

- `post`：校验登录、reply_policy 枚举、media id 列表（允许 pending）、成绩卡引用、@提及服务端解析校验、`client_req_id` 幂等
- `feed`：`tab=recommend` 全站有效帖 shuffle 伪随机+过滤软删/封禁/同批去重；`tab=follow` 按 follows 时间序+游标；无关注空态 `empty:"no_follow"`
- `like/unlike`、`comment`（服务端强制 reply_policy，越权 403 `forbidden_reply`）、`repost`（生成 type:repost 新帖引用原帖）、`bookmark/unbookmark`（均幂等，计数服务端推导）
- `search_users`（@提及搜索）、`score_cards`（成绩卡选择器，只回卡片字段）、`comments_of`（评论展开+游标）
- `del`：帖/评论软删（status=deleted 保留记录），删帖同时清理关联媒体文件与 manifest
- `follow/unfollow/following/followers`（canonical 落 follows.php）、`notify/notify_read/unread`、`staff_card`（不吐 email）
- `media.php?action=upload` / `raw`：见 §5.1

### 5.1 媒体架构（Mac 侧压缩工人，已定稿）

上传后入 `uploads/_pending/` 并记 manifest `status:pending`；Mac 工人轮询拉取处理后回传正式区并置 `ready`。

- **传输**：rsync over ssh 在本环境被封，改用 **ssh `cat` 管道**（与 deploy.sh 同法；禁止 base64 传大文件）。
- **图片**：>3MB → sips 压至最大边 1080p（orig，原图保留）；派生双缩略图 sm(360px)/md(720px)；≤3MB 原图保留。
- **视频**：>50MB → ffmpeg（`~/.local/bin/ffmpeg` v6.0，NAS 裁剪版不可用）压 720p h264+aac；**压缩后原视频删除（NAS+Mac 均无残留），仅 720p 可播**。
- **音频**：不压缩，原样回传。
- **三态**：pending（Mac 处理中/离线停留）→ ready（正常展示）/ failed（前端"处理失败"可删重发）。
- **运维**：Mac 24×7 待机，工人 45s 轮询；**NAS 周三 01:30-08:00 停机**——工人指数退避静默重试（上限 5min）、不崩溃不刷日志。
- **安全**：finfo 真实 MIME + 扩展名双白名单（jpg/png/webp/gif/mp4/webm/mp3/m4a/wav）、随机 16hex 名、`uploads/` 全目录 `.htaccess` 禁脚本执行、raw 回放二次白名单+realpath 防穿越。
- **大小上限**：图片 ≤10MB、音频 ≤50MB、视频 ≤200MB（部署项：NAS Web Station PHP8.2 `upload_max_filesize/post_max_size` 调至 256M）。

## 6. 前端改动

- kaohe.html：⚡动态 tab 由占位 → 真实动态页；发帖面板、帖子卡片、评论展开、媒体预览/播放；四 tab 导航「动态」激活态蓝；加载/空/错误/提交/失效各状态（总览 §12.2）
- 版本号 v3.5.0；橙色残留 0；三端适配

## 7. 验收矩阵（v3.5.0 已回填，明细见 `artifacts/复核包-MVP5-v3.5.0.md` §2）

> 测试环境：本地 `php -S 127.0.0.1:8899`，数据隔离于本地 kaohe-data/；账号 m5qa01~04/m5admin（全合成）。共 54 条，✅=通过 ⚠️=部分。

| 能力 | 结果 | 关键证据 |
|---|---|---|
| 1 发帖（正常/空/501字/连点仅1条/未登录） | ✅ 5/5 | `client_req_id` dup 幂等；未登录 401 |
| 2 媒体（白名单/超限/伪造扩展名/错MIME/中断/第5个拒/图片压缩/双缩略图/点击orig/视频720p/原片删除/音频原样/三态流转/Mac离线占位/failed可删/缓存清理） | ✅ 15/16，⚠️1 | 2.2 超限被本地内置服务器 post_max_size=8M 先行拦截（代码阈值就绪，部署 ini 调 256M 后生效） |
| 3 推荐流（加载/3次下拉有变化同批去重/空态/发5条无重复/封禁过滤/删帖消失notfound） | ✅ 6/6 | shuffle 两次顺序不同；软删帖已过滤 |
| 4 关注流（见不见无关人/取消即消失/无关注空态/follows缺失不崩/时间倒序） | ✅ 5/5 | qa04 `empty:"no_follow"` |
| 5 互动（点赞toggle/连点5次/50并发锁/评论+通知/空超长评论/转发新帖/书签幂等/重登点亮态/删帖后notfound） | ✅ 9/9 | **50 并发点赞 records=50、distinctUsers=50、JSON OK** |
| 6 @提及（高亮+通知/不存在skipped/搜索多选/XSS转义/评论里@） | ✅ 5/5 | mentions=[] skipped |
| 7 reply_policy（小标/直调绕过403/已关注可评/仅提及语义/公开全员可评） | ✅ 5/5 | curl 直调 → HTTP 403 `forbidden_reply` |
| 8 持久化（刷新重登/断网不双份/双浏览器并发/损坏不白屏/删帖清理媒体） | ✅ 5/5 | 写坏 social.php 仍 `{ok:true,posts}` |
| 9 三端（375/768/1280/Console无错/无PHP Fatal/橙色清零） | ✅ 6/6 | NAS php82 lint 4 文件全过 |
| 10 零回归（登录/四tab/我的页/今日打卡/管理员/跨用户隔离） | ✅ 6/6 | auth/admin 逻辑未改 |
| 11 待部署（git干净/白名单不含新文件/线上未触碰/页脚v3.5.0） | ✅ 4/4 | 未运行 deploy.sh |

## 8. 完成定义（DoD，v3.5.0 已勾选）

- ☑ 后端接口按契约实现并自测
- ☑ 前端动态页全链路联调
- ☑ 并发锁验证（50 并发点赞无丢失更新）
- ☑ 媒体三态流转（pending→ready/failed）
- ☑ 三端布局正常、无阻断错误、无 PHP Fatal
- ☑ MVP-1~4 零回归
- ☑ 数据文件命名定稿、文档同步（§4/§5）
- ☑ 停在待部署（未上线；deploy.sh 白名单待扩展）
- ☐ git 提交号：⬜ 提交后回填

## 9. 恢复开发时的第一步动作

1. 读本文件 + 总览 §8（执行基线）+ `kaohe-api/social.php` 全文
2. 冻结未定口径（总览 §2.3：回复范围语义/删除语义/评论规则/转发对象/伪随机刷新与去重/编辑语义）
3. 定稿数据文件命名与 schema → 4. 开发 → 5. 自测 → 6. 待部署

## 10. 维护与更新规则

- 本 MVP 范围有任何改动（功能/修 bug/数据模型变更），同步更新本文档并更新总览第 3/7/9/10 节。
- 完成上线后：状态 → ✅ 已上线，并在总览第 3.1 节版本表登记。
