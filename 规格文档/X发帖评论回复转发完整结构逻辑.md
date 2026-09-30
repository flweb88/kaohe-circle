# X（原 Twitter）发帖 / 评论 / 回复 / 转发机制完整产品与数据结构

> 版本：2026-10-01  
> 目标：作为产品设计、数据库设计、后端 API、前端时间线和 AI Agent 实现的参考规格。  
> 范围：X（原 Twitter）的公开可见产品机制，不是 X 内部源代码或未公开数据库 schema。
>
> 重要说明：X 的公开帮助中心把“回复（Reply）”“普通帖子（Post）”“Repost（转发）”“Quote post（引用转发）”视为不同类型/行为。实际内部实现细节并未全部公开，因此本文把**公开可观察行为**与**适合工程实现的抽象模型**分开。

---

# 1. 核心结论：X 不是“四种完全独立的内容”

理解 X 最重要的一点：

```text
                         ┌──────────────┐
                         │    Account   │
                         └──────┬───────┘
                                │ author
                                ▼
                         ┌──────────────┐
                         │     Post     │
                         └──────┬───────┘
                                │
              ┌─────────────────┼──────────────────┐
              │                 │                  │
              ▼                 ▼                  ▼
         Reply/回复         Repost/转发       Quote/引用转发
              │                 │                  │
              │                 │                  └── 新 Post
              │                 │                      + 引用原 Post
              │                 │
              │                 └── 用户与原 Post 的关系
              │
              └── 新 Post
                  + reply_to 原 Post
```

因此从工程角度，最合理的抽象不是：

```text
Post
Comment
Reply
Repost
```

四张互相独立的内容表。

而是：

```text
Post
 ├── original post
 ├── reply post
 ├── quote post
 └── repost relation
```

也就是说：

> **Reply 和 Quote 本质上都可以理解为“一个新的 Post + 对另一个 Post 的引用关系”；纯 Repost 则更接近“用户与已有 Post 之间建立的一条分享关系”，而不是重新复制一份正文。**

X 官方明确把 Reply 定义为对另一个 Post 的响应；Quote post 则是带有自己评论的引用分享；普通 Repost 是直接分享原 Post。  
citeturn0search2turn0search3

---

# 2. 四种核心行为

| 行为 | 是否产生新的 Post 内容对象 | 是否产生正文 | 是否指向原 Post | 是否进入作者自己的内容时间线 |
|---|---:|---:|---:|---:|
| 普通发帖 | 是 | 是 | 否 | 是 |
| 回复 Reply | 是 | 是 | 是 | 是 |
| 转发 Repost | 通常不是新的正文 Post | 否 | 是 | 作为 Repost 展示 |
| 引用转发 Quote | 是 | 是，可为空/按产品规则 | 是 | 是 |
| 连续发帖 Thread | 多个 | 是 | 前后串联 | 是 |

核心区别：

```text
普通 Post
    ↓
独立内容

Reply
    ↓
新内容
    ↓
reply_to = 原 Post

Quote
    ↓
新内容
    ↓
quoted_post_id = 原 Post

Repost
    ↓
不是复制原内容
    ↓
repost(user_id, post_id)
```

---

# 3. Post 是核心对象

建议把所有实际发表出来的文本/媒体内容统一抽象为：

```text
Post
```

而不是分别建立：

```text
Tweet
Comment
Reply
Quote
```

## 3.1 Post 最核心字段

```json
{
  "id": "post_123",
  "author_id": "user_001",

  "text": "今天发生了一件有意思的事",

  "created_at": "2026-10-01T10:00:00Z",
  "updated_at": null,

  "visibility": "public",

  "reply_settings": "everyone",

  "reply_to_post_id": null,
  "conversation_id": "post_123",

  "quoted_post_id": null,

  "language": "zh",

  "is_deleted": false,
  "deleted_at": null,

  "like_count": 0,
  "reply_count": 0,
  "repost_count": 0,
  "quote_count": 0,
  "bookmark_count": 0,
  "view_count": 0
}
```

---

# 4. Post 类型不要只用一个 type 字段解决

推荐：

```text
post
 ├── original
 ├── reply
 ├── quote
 └── thread_member
```

但是工程上不要完全依赖：

```text
type = "reply"
```

更重要的是保存**关系字段**。

例如：

```json
{
  "id": "post_B",
  "author_id": "user_B",
  "text": "我同意这个观点",
  "reply_to_post_id": "post_A",
  "conversation_id": "post_A"
}
```

这就足以说明：

```text
post_B 是对 post_A 的回复
```

Quote：

```json
{
  "id": "post_C",
  "author_id": "user_C",
  "text": "这个观点值得讨论",
  "quoted_post_id": "post_A"
}
```

---

# 5. 普通发帖

普通发帖是最基础的 Post：

```text
User
 ↓
Compose
 ↓
Create Post
 ↓
Post
```

例如：

```text
张三：

今天开始研究人工智能 Agent。
```

数据：

```json
{
  "id": "P001",
  "author_id": "U001",
  "text": "今天开始研究人工智能 Agent。",
  "reply_to_post_id": null,
  "quoted_post_id": null
}
```

这是：

```text
original post
```

---

# 6. 回复 Reply

## 6.1 回复不是“评论字段”

例如：

```text
A：

苹果发布了新产品。

B：

我觉得这次变化挺大的。
```

B 的内容实际上仍然是一条 Post：

```text
Post B
```

只是：

```text
reply_to_post_id = Post A
```

因此：

```text
Post A
  │
  ├── Reply B
  ├── Reply C
  └── Reply D
```

---

# 7. 回复的两种关系必须区分

这是设计 X 类产品时非常重要的一点。

## 7.1 直接回复对象

```text
reply_to_post_id
```

表示：

> 我点击“回复”的到底是哪一条 Post。

例如：

```text
A
└── B 回复 A
    └── C 回复 B
```

那么：

```text
B.reply_to_post_id = A.id

C.reply_to_post_id = B.id
```

---

# 8. Conversation ID

同时需要：

```text
conversation_id
```

它表示：

> 这条 Post 属于哪一个完整讨论串。

例如：

```text
A
├── B
│   ├── D
│   └── E
└── C
```

所有这些可以属于：

```text
conversation_id = A.id
```

于是：

```text
A.conversation_id = A.id
B.conversation_id = A.id
C.conversation_id = A.id
D.conversation_id = A.id
E.conversation_id = A.id
```

因此：

```text
reply_to_post_id
```

解决：

> 我直接回复谁？

而：

```text
conversation_id
```

解决：

> 我属于哪个完整讨论？

这是两个不同概念。

X 的公开说明也明确区分了直接 Reply 数量与整个 conversation 中的回复数量：一个 Post 显示的 reply count 是其**直接回复数**，并不是整个会话树的所有回复总数。  
citeturn0search4

---

# 9. 回复树

建议数据库关系：

```text
Post A
│
├── Post B
│   │
│   ├── Post D
│   └── Post E
│
└── Post C
```

对应：

```text
A
├── B
│   ├── D
│   └── E
└── C
```

数据：

```text
A.reply_to = null

B.reply_to = A

C.reply_to = A

D.reply_to = B

E.reply_to = B
```

---

# 10. 为什么不能只用 parent_id

如果只有：

```text
parent_id
```

虽然能够形成树，但不方便：

```text
查询整个 Conversation
分页
排序
推荐
搜索
统计
删除级联
缓存
```

因此建议同时保存：

```text
parent_post_id
conversation_id
```

即：

```text
parent_post_id
    ↓
直接父节点

conversation_id
    ↓
整个讨论串
```

---

# 11. 回复数量

不要定义成：

```text
reply_count = conversation 中所有回复
```

而应该：

```text
reply_count
=
直接 reply_to_this_post 的 Post 数量
```

例如：

```text
A
├── B
│   └── D
└── C
```

那么：

```text
A.reply_count = 2
B.reply_count = 1
C.reply_count = 0
D.reply_count = 0
```

而不是：

```text
A.reply_count = 3
```

X 官方帮助中心明确说明，Post 上显示的 reply count 是**直接回复数量**，不是整个 conversation 的回复总量。  
citeturn0search4

---

# 12. 转发 Repost

普通 Repost 与 Reply 完全不同。

用户：

```text
A 发布：

今天苹果发布新品。
```

用户 B：

```text
点击 Repost
```

不是：

```text
复制 A 的文字
创建 B 的新 Post
```

而是建立：

```text
B ──repost──> A
```

即：

```text
RepostRelation
```

建议：

```json
{
  "user_id": "B",
  "post_id": "A",
  "created_at": "...",
  "type": "repost"
}
```

X 官方定义 Repost 为把一个 Post 分享给自己的 followers；取消 Repost 会移除这次分享，但不会删除原 Post。  
citeturn0search0turn0search3

---

# 13. Repost 不应该复制正文

错误设计：

```text
B 发布：

今天苹果发布新品。

source_post_id = A
```

这样会造成：

```text
A 被删除
B 仍然存在一份复制内容
```

而 X 的 Repost 是引用原始 Post 的分享关系。

因此建议：

```text
Original Post
      ↑
      │
 RepostRelation
      │
      ↑
     User
```

---

# 14. Repost 的核心数据模型

```text
reposts

id
user_id
post_id
created_at
```

唯一约束：

```text
UNIQUE(user_id, post_id)
```

防止：

```text
同一个用户
对同一个 Post
重复 Repost
```

---

# 15. 取消 Repost

取消：

```text
DELETE repost_relation
```

而不是：

```text
DELETE original_post
```

因此：

```text
A 原帖
+
B Repost
```

B 取消：

```text
A 原帖仍然存在
B 的 Repost 关系消失
```

X 官方也明确说明 Undo Repost 不会删除原始 Post。  
citeturn0search0

---

# 16. Quote Post：引用转发

这是最容易和 Repost 混淆的地方。

用户 B：

```text
A：

中国新能源汽车销量继续增长。
```

B 点击：

```text
Quote
```

然后写：

```text
这个趋势可能会持续很多年。
```

最终：

```text
B：

这个趋势可能会持续很多年。

┌─────────────────────────┐
│ A                       │
│ 中国新能源汽车销量...   │
└─────────────────────────┘
```

这里实际上是：

```text
一个新的 Post B
+
引用 A
```

所以：

```text
B.quoted_post_id = A.id
```

---

# 17. Quote 与 Reply 的关键区别

Reply：

```text
B
│
└── reply_to = A
```

Quote：

```text
B
│
└── quoted_post = A
```

语义完全不同。

### Reply

```text
我正在和 A 对话。
```

### Quote

```text
我正在向我的受众分享 A，并发表自己的看法。
```

---

# 18. Quote 不等于 Reply

这是工程实现最容易犯的错误。

错误：

```text
Quote
=
Reply
+
转发按钮
```

实际上应该：

```text
Quote
=
新 Post
+
quoted_post_id
```

Quote 可以不把自己加入原 Post 的 reply conversation。

X 官方明确说明：**回复一个 Quote post 时，原始 Post 的作者不会自动加入这个新的 conversation；如果想让原作者参与，需要显式 @mention。**  
citeturn0search3

---

# 19. Quote 的数据库模型

```text
Post
```

直接使用：

```json
{
  "id": "P200",
  "author_id": "U200",
  "text": "这个趋势可能持续很多年。",
  "quoted_post_id": "P100",
  "reply_to_post_id": null,
  "conversation_id": "P200"
}
```

注意：

```text
conversation_id = P200
```

而不是：

```text
conversation_id = P100
```

因为 Quote 本身是一个新的内容节点。

---

# 20. 四种关系最终模型

可以统一成：

```text
                 ┌─────────────┐
                 │    POST     │
                 └──────┬──────┘
                        │
       ┌────────────────┼─────────────────┐
       │                │                 │
       ▼                ▼                 ▼
   reply_to        quoted_post       original
       │                │
       ▼                ▼
   Reply Post       Quote Post


                 REPOST
                   │
                   ▼
             RepostRelation
                   │
                   ▼
               Original Post
```

---

# 21. 一个完整例子

假设：

### A 发帖

```text
P1

A：
今天苹果发布新品。
```

---

### B 回复

```text
P2

B：
我觉得设计变化很大。
```

关系：

```text
P2.reply_to_post_id = P1
P2.conversation_id = P1
```

---

### C 回复 B

```text
P3

C：
我倒觉得变化不大。
```

关系：

```text
P3.reply_to_post_id = P2
P3.conversation_id = P1
```

---

### D 直接 Repost

```text
D → Repost → P1
```

数据库：

```text
reposts
D / P1
```

---

### E Quote

```text
E：

我觉得真正值得关注的是价格。

[引用 P1]
```

数据库：

```text
P5.quoted_post_id = P1
P5.reply_to_post_id = null
P5.conversation_id = P5
```

---

# 22. 最终关系图

```text
P1
│
├── P2
│   │
│   └── P3
│
└── Repost(D)

P5
│
└── Quote(P1)
```

注意：

```text
P5 ≠ P1 conversation 的 child
```

因为：

```text
Quote
```

不是：

```text
Reply
```

---

# 23. Thread：连续发帖

X 还有 Thread。

例如：

```text
P1：
今天我想讲一个比较复杂的问题。

P2：
首先我们来看人口。

P3：
其次看财政。

P4：
最后看资产负债表。
```

可以设计：

```text
P1
 ↓
P2
 ↓
P3
 ↓
P4
```

字段：

```text
conversation_id = P1
reply_to_post_id:
P2 → P1
P3 → P2
P4 → P3
```

因此：

> Thread 可以使用 Reply 关系实现。

但产品 UI 可以把它识别为：

```text
author 相同
+
同一 conversation
+
连续 reply
```

从而渲染成一个线程。

---

# 24. Thread 与普通 Reply 的区别

数据库层面：

```text
Thread reply
```

和：

```text
普通 reply
```

可以是同一种 Post。

区别主要来自：

```text
author
conversation
父子关系
创建行为
UI 展示
```

因此不建议建立：

```text
ThreadPost
```

作为完全独立的数据模型。

---

# 25. Mention

Mention 不是 Reply。

例如：

```text
今天我要感谢 @elonmusk。
```

这是：

```text
Post
+
Mention
```

而不是：

```text
Reply
```

建议单独建：

```text
post_mentions
```

字段：

```text
id
post_id
mentioned_user_id
start_position
end_position
created_at
```

---

# 26. @用户名开头也不一定是 Reply

例如：

```text
@elonmusk 你怎么看？
```

如果用户是普通发帖：

```text
reply_to_post_id = null
```

那么它仍然只是：

```text
普通 Post + Mention
```

只有用户通过 Reply 行为响应某条 Post，才是 Reply。

---

# 27. 媒体

一个 Post 可以包含：

```text
text
image
video
GIF
poll
link
```

建议媒体独立：

```text
media
```

然后：

```text
post_media
```

例如：

```text
Post
 │
 ├── Media 1
 ├── Media 2
 ├── Media 3
 └── Media 4
```

而不是把：

```text
image_url_1
image_url_2
image_url_3
```

硬编码进 Post 表。

---

# 28. 推荐的数据结构

```text
posts
```

```text
id
author_id

text

reply_to_post_id
conversation_id
quoted_post_id

visibility
reply_settings

language

created_at
updated_at

is_deleted
deleted_at

like_count
reply_count
repost_count
quote_count
bookmark_count
view_count
```

---

# 29. Media

```text
media

id
owner_id

type
url
thumbnail_url

width
height
duration

alt_text

created_at
```

---

# 30. Post Media

```text
post_media

post_id
media_id
sort_order
```

这样：

```text
Post
 ↓
post_media
 ↓
Media
```

支持：

```text
一个 Post 多个媒体
一个媒体是否允许被多个 Post 引用
媒体排序
```

---

# 31. Like

Like 不应该放成：

```text
post.like_user_ids
```

而应该：

```text
likes

id
user_id
post_id
created_at
```

唯一约束：

```text
UNIQUE(user_id, post_id)
```

---

# 32. Bookmark

同理：

```text
bookmarks

id
user_id
post_id
created_at
```

---

# 33. Repost

```text
reposts

id
user_id
post_id
created_at
```

---

# 34. Quote

Quote 不需要：

```text
quotes
```

也可以直接：

```text
posts.quoted_post_id
```

例如：

```text
posts

id = P10
quoted_post_id = P5
```

表示：

```text
P10 Quote P5
```

---

# 35. Reply

同理：

```text
posts.reply_to_post_id
```

表示：

```text
P10 Reply P5
```

因此：

```text
Quote
Reply
```

都可以通过 Post 表实现。

---

# 36. 最推荐的核心关系模型

```text
                    POST
                     │
        ┌────────────┼─────────────┐
        │            │             │
        ▼            ▼             ▼
   reply_to      quoted_post     media
        │            │
        │            │
        ▼            ▼
   Conversation      Quote
        │
        ▼
      Reply


                    POST
                     ▲
                     │
                  repost
                     │
                  REPOST
                     │
                     ▼
                    USER
```

---

# 37. 时间线不是数据库中的 Post 列表

这是实现 X 类产品最关键的部分之一。

不要认为：

```sql
SELECT *
FROM posts
ORDER BY created_at DESC;
```

就是首页。

X 的 Home Timeline 是经过推荐、关系、相关性、安全等多种因素处理的 Feed。

官方说明中也明确表示，用户有时会看到自己没有关注的账号的内容，推荐会考虑内容流行程度、网络互动、相关性等信号。  
citeturn0search2

所以应该分：

```text
Content Store
        ↓
Candidate Generation
        ↓
Filtering
        ↓
Ranking
        ↓
Timeline
```

---

# 38. Profile Timeline

用户自己的 Profile 可以理解为：

```text
自己的原创 Post
+
自己的 Reply
+
自己的 Quote
+
自己的 Repost 展示
```

但产品 UI 可以采用不同 tab：

```text
Posts
Replies
Media
Likes
```

具体展示规则由产品决定。

---

# 39. Home Timeline

Home Timeline 的候选内容可能来自：

```text
你关注的人
+
你关注的人 Repost 的内容
+
你关注的人 Quote 的内容
+
推荐内容
+
热门内容
+
相关 conversation
```

因此：

```text
Post ≠ TimelineItem
```

这是非常重要的架构原则。

---

# 40. TimelineItem 建议独立

推荐：

```text
timeline_items
```

或者在 Feed 服务层动态生成：

```text
TimelineItem
```

它描述：

```text
为什么这个 Post 出现在这里？
```

例如：

```json
{
  "post_id": "P100",
  "display_type": "repost",
  "reason": {
    "type": "reposted_by",
    "user_id": "U10"
  }
}
```

于是 UI：

```text
张三 Reposted

李四
今天发生了一件事……
```

---

# 41. Repost 与 TimelineItem 的关系

不要把 Repost 复制成 Post。

应该：

```text
TimelineItem
       │
       ▼
Original Post
       ▲
       │
Repost Relation
       ▲
       │
     User
```

---

# 42. Quote 的 TimelineItem

Quote 是：

```text
一个真正的新 Post
```

所以：

```text
TimelineItem
       │
       ▼
Quote Post
       │
       ▼
Quoted Post
```

UI：

```text
张三

这个观点值得讨论。

┌─────────────────────┐
│ 李四                 │
│ 原始内容……           │
└─────────────────────┘
```

---

# 43. Reply 的 TimelineItem

Reply：

```text
TimelineItem
       │
       ▼
Reply Post
       │
       ▼
Parent Post
```

但首页中是否显示：

```text
reply
```

取决于：

```text
关系
相关性
推荐
用户是否关注双方
conversation context
```

X 官方说明中，其他人的 Reply 并不一定按时间顺序展示；回复会被按相关性、安全等因素组织，并可能根据用户关系和其他信号排序。  
citeturn0search1turn0search4

---

# 44. 回复排序

不要简单：

```sql
ORDER BY created_at DESC
```

建议：

```text
Reply Candidates
       ↓
Spam Filter
       ↓
Safety Filter
       ↓
Relationship Signal
       ↓
Author Signal
       ↓
Engagement Signal
       ↓
Quality / Relevance
       ↓
Ranking
```

然后：

```text
Top Replies
↓
Other Replies
↓
Potential Spam
```

X 官方也说明，conversation 中的 replies 不一定按时间顺序显示。  
citeturn0search1turn0search4

---

# 45. 隐藏 Reply

X 允许原始 Post 作者隐藏别人对其 Post 的 Reply。

注意：

```text
隐藏 ≠ 删除
```

原 Reply 仍然存在。

建议：

```text
post_reply_moderations

post_id
reply_post_id
action
actor_id
created_at
```

例如：

```json
{
  "post_id": "P1",
  "reply_post_id": "P2",
  "action": "hidden",
  "actor_id": "U1"
}
```

X 官方说明，隐藏 Reply 后，Reply 作者不会收到通知；隐藏回复仍可通过隐藏回复入口访问，但受保护账号或已删除回复等情况会有额外限制。  
citeturn0search1

---

# 46. Hide Reply 不要 delete

错误：

```text
DELETE FROM posts
```

正确：

```text
reply_visibility = hidden
```

或者：

```text
moderation action = hidden
```

这样：

```text
原始内容
+
回复关系
+
回复数据
```

仍然存在。

---

# 47. Reply Settings

一个 Post 需要：

```text
reply_settings
```

例如：

```text
everyone
following
mentioned
verified
```

X 当前公开帮助中心列出了不同 Reply 控制选项，包括 Everyone、Accounts you follow、Only accounts you mention，以及 Only verified accounts 等。  
citeturn0search4

---

# 48. Reply 权限判断

用户 B 想回复 Post A：

```text
CanReply(B, A)
```

判断：

```text
A.reply_settings
+
A.author relationship
+
B 是否被 block
+
B 是否被限制
+
A visibility
+
B visibility
+
平台安全策略
```

最终：

```text
true / false
```

---

# 49. Reply 权限和 Repost 权限不能混为一谈

例如：

```text
允许别人 Repost
```

不意味着：

```text
允许别人 Reply
```

因此应该独立判断：

```text
CanReply
CanRepost
CanQuote
CanLike
CanBookmark
```

---

# 50. Protected Account

如果用户把账号设为 Protected：

```text
Public
   ↓
Protected
```

那么：

```text
只有获准 followers 可以查看
```

其 Reply / Mention 也受到相应可见性限制。

X 官方说明，受保护账号的 Post 只有获准 followers 可以查看；受保护账号发送的 Reply / Mention 也只对获准用户可见。  
citeturn0search2turn0search5

---

# 51. Visibility 应该独立于 Post Type

不要：

```text
type = private
```

建议：

```text
visibility
```

例如：

```text
public
followers
mentioned
restricted
deleted
```

因为：

```text
Original
Reply
Quote
```

都可能拥有不同的可见性条件。

---

# 52. Block

Block 是用户关系：

```text
blocks

blocker_id
blocked_id
created_at
```

不是 Post 属性。

但是 Feed / Reply / Repost 权限判断都会读取：

```text
Block Graph
```

例如：

```text
CanReply(A, P)
```

先判断：

```text
P.author 是否 block A
A 是否 block P.author
```

---

# 53. Mute

Mute 也是用户关系：

```text
mutes

muter_id
muted_id
created_at
```

Mute 通常影响：

```text
Feed
Notification
Recommendation
```

而不是简单删除对方的 Post。

X 官方说明，Mute 不会通知被 mute 的账号，并主要影响用户在时间线中看到的内容。  
citeturn0search5

---

# 54. Notification

发帖系统必须和 Notification 分离。

例如：

```text
A 发 Post
B Reply A
```

产生：

```text
Notification
```

而不是把 Notification 直接塞到 Post。

推荐：

```text
notifications

id
recipient_id
actor_id

type

post_id
related_post_id

created_at
read_at
```

类型：

```text
like
reply
repost
quote
mention
follow
```

---

# 55. Reply Notification

例如：

```text
A:
P1

B:
P2 reply P1
```

Notification：

```json
{
  "recipient_id": "A",
  "actor_id": "B",
  "type": "reply",
  "post_id": "P2",
  "related_post_id": "P1"
}
```

---

# 56. Quote Notification

例如：

```text
B Quote P1
```

可以产生：

```text
recipient = P1.author
type = quote
post_id = B's quote
related_post_id = P1
```

---

# 57. Repost Notification

```text
B Repost P1
```

产生：

```text
recipient = P1.author
actor = B
type = repost
post = P1
```

---

# 58. Like / Reply / Repost / Quote 统计

Post 可以缓存：

```text
like_count
reply_count
repost_count
quote_count
```

但是这些应该是：

```text
derived counters
```

而不是唯一真实数据源。

真正数据：

```text
likes
reposts
posts
```

计数器用于：

```text
高性能读取
```

---

# 59. Counter 一致性

例如：

```text
reposts = 10000000
```

不能每次：

```sql
SELECT COUNT(*)
FROM reposts
WHERE post_id = ?
```

建议：

```text
reposts table
+
post.repost_count
```

异步或事务更新：

```text
Repost
 ↓
Insert relation
 ↓
Increment counter
 ↓
Event
```

---

# 60. Event Architecture

如果做大规模 X 类系统，建议引入：

```text
PostCreated
PostDeleted
PostEdited

ReplyCreated
RepostCreated
RepostRemoved
QuoteCreated

LikeCreated
LikeRemoved

FollowCreated
BlockCreated
MuteCreated
```

例如：

```json
{
  "event": "post.created",
  "post_id": "P100",
  "author_id": "U100",
  "created_at": "..."
}
```

下游服务：

```text
Feed
Notification
Search
Recommendation
Analytics
Moderation
Counters
```

都可以消费 Event。

---

# 61. 删除 Post

删除不能简单理解成：

```text
DELETE FROM posts
```

需要考虑：

```text
Reply
Repost
Quote
Notification
Search Index
Feed Cache
Counters
Bookmarks
Likes
Media
```

建议：

```text
soft delete
```

例如：

```json
{
  "is_deleted": true,
  "deleted_at": "..."
}
```

---

# 62. 原 Post 被删除

假设：

```text
P1
├── P2 Reply
├── Repost B
└── P5 Quote
```

删除 P1 后：

```text
P1 = deleted
```

不能简单：

```text
DELETE P2
DELETE P5
```

更合理：

```text
P2
 ↓
仍保留关系
 ↓
父 Post 不可用
```

UI：

```text
This post is unavailable.
```

而：

```text
Quote P5
```

可以显示：

```text
引用的原始 Post 不可用
```

具体展示策略由产品定义。

X 官方说明，原始 Post 被删除或被移除后，与之相关的 Repost 也会不再可用。  
citeturn0search0

---

# 63. Quote 和原 Post 删除

Quote：

```text
P5
quoted_post_id = P1
```

P1 删除：

```text
P5 仍然是一个独立 Post
```

但：

```text
quoted content
```

变为：

```text
Unavailable
```

所以：

```text
Quote != copy
```

这是非常重要的产品设计。

---

# 64. Repost 和 Quote 的生命周期区别

```text
Repost
=
关系
```

所以：

```text
原 Post 删除
→ Repost 消失
```

Quote：

```text
新 Post
+
引用关系
```

所以：

```text
原 Post 删除
→ Quote Post 本身仍然存在
→ 引用区域不可用
```

---

# 65. Edit

X 目前允许部分 Post 和 Quote 在发布后一段时间内编辑，但官方说明 Reply、Thread、Poll、Pinned Post 等类型存在限制；当前公开规则中，编辑窗口为发布后 1 小时，并允许在窗口内最多 5 次编辑。  
citeturn0search6

工程上建议：

```text
post_versions
```

而不是直接覆盖所有历史数据。

---

# 66. Post Version

```text
post_versions

id
post_id
version
text
media_snapshot
created_at
created_by
```

例如：

```text
P1
 ├── version 1
 ├── version 2
 └── version 3
```

这样可以实现：

```text
Edit History
```

---

# 67. 为什么需要 Version

因为：

```text
用户看到的当前内容
```

和：

```text
过去已经发布过的内容
```

可能不同。

X 官方说明，编辑后的 Post 会标记为 edited，并允许查看此前发布的版本。  
citeturn0search6

---

# 68. 不能只保存 current_text

错误：

```text
posts.text
```

每次编辑直接：

```text
UPDATE posts
SET text = ...
```

这样会丢失：

```text
历史版本
```

建议：

```text
posts.current_version_id
```

+

```text
post_versions
```

---

# 69. 内容对象和关系对象分离

最终建议：

```text
CONTENT
│
└── Post

RELATION
│
├── Reply → Post.parent
├── Quote → Post.quoted
├── Repost → RepostRelation
├── Like → LikeRelation
├── Bookmark → BookmarkRelation
├── Mention → MentionRelation
└── Follow → UserRelation
```

这是整个系统最核心的设计思想。

---

# 70. 推荐数据库 ER

```text
┌──────────────┐
│    users     │
└──────┬───────┘
       │
       │ author
       ▼
┌──────────────────────────┐
│          posts           │
├──────────────────────────┤
│ id                       │
│ author_id                │
│ text                     │
│ reply_to_post_id         │──────┐
│ conversation_id          │      │
│ quoted_post_id           │──┐   │
│ visibility               │  │   │
│ reply_settings           │  │   │
│ created_at               │  │   │
└────────────┬─────────────┘  │   │
             │                │   │
             ▼                │   │
       ┌────────────┐         │   │
       │ post_media │         │   │
       └─────┬──────┘         │   │
             ▼                │   │
          media               │   │
                              │   │
                              │   │
       ┌──────────────┐       │   │
       │    reposts   │◄──────┘   │
       └──────────────┘           │
                                  │
                                  │
                         self reference
```

---

# 71. 推荐完整表结构

核心：

```text
users
posts
post_versions
media
post_media
```

互动：

```text
likes
reposts
bookmarks
```

关系：

```text
follows
blocks
mutes
mentions
```

通知：

```text
notifications
```

治理：

```text
post_moderations
reply_moderations
reports
```

Feed：

```text
timeline_items
feed_events
```

分析：

```text
post_impressions
post_engagement_events
```

---

# 72. posts 表推荐

```sql
CREATE TABLE posts (
    id                  BIGINT PRIMARY KEY,

    author_id           BIGINT NOT NULL,

    text                TEXT,

    reply_to_post_id    BIGINT NULL,
    conversation_id     BIGINT NOT NULL,
    quoted_post_id      BIGINT NULL,

    visibility          VARCHAR(32) NOT NULL DEFAULT 'public',
    reply_settings      VARCHAR(32) NOT NULL DEFAULT 'everyone',

    language            VARCHAR(16),

    created_at          TIMESTAMP NOT NULL,
    updated_at          TIMESTAMP NULL,

    is_deleted          BOOLEAN NOT NULL DEFAULT FALSE,
    deleted_at          TIMESTAMP NULL,

    like_count          BIGINT NOT NULL DEFAULT 0,
    reply_count         BIGINT NOT NULL DEFAULT 0,
    repost_count       BIGINT NOT NULL DEFAULT 0,
    quote_count         BIGINT NOT NULL DEFAULT 0,
    bookmark_count      BIGINT NOT NULL DEFAULT 0,
    view_count          BIGINT NOT NULL DEFAULT 0
);
```

---

# 73. 关键索引

```sql
INDEX(author_id, created_at DESC)

INDEX(conversation_id, created_at)

INDEX(reply_to_post_id, created_at)

INDEX(quoted_post_id, created_at)

INDEX(created_at)

INDEX(is_deleted, created_at)
```

---

# 74. Repost 表

```sql
CREATE TABLE reposts (
    id          BIGINT PRIMARY KEY,

    user_id     BIGINT NOT NULL,
    post_id     BIGINT NOT NULL,

    created_at  TIMESTAMP NOT NULL,

    UNIQUE(user_id, post_id)
);
```

---

# 75. Like 表

```sql
CREATE TABLE likes (
    id          BIGINT PRIMARY KEY,

    user_id     BIGINT NOT NULL,
    post_id     BIGINT NOT NULL,

    created_at  TIMESTAMP NOT NULL,

    UNIQUE(user_id, post_id)
);
```

---

# 76. Bookmark 表

```sql
CREATE TABLE bookmarks (
    id          BIGINT PRIMARY KEY,

    user_id     BIGINT NOT NULL,
    post_id     BIGINT NOT NULL,

    created_at  TIMESTAMP NOT NULL,

    UNIQUE(user_id, post_id)
);
```

---

# 77. Mention 表

```sql
CREATE TABLE mentions (
    id                  BIGINT PRIMARY KEY,

    post_id             BIGINT NOT NULL,
    mentioned_user_id   BIGINT NOT NULL,

    start_position      INT,
    end_position        INT,

    created_at          TIMESTAMP NOT NULL
);
```

---

# 78. 创建普通 Post

API：

```http
POST /posts
```

请求：

```json
{
  "text": "今天研究一下 AI Agent。",
  "media_ids": [],
  "reply_settings": "everyone",
  "visibility": "public"
}
```

返回：

```json
{
  "id": "P100",
  "type": "original"
}
```

---

# 79. 创建 Reply

API：

```http
POST /posts
```

请求：

```json
{
  "text": "我也这么认为。",
  "reply_to_post_id": "P100"
}
```

后端：

```text
validate post
↓
validate permission
↓
find conversation_id
↓
create post
↓
conversation_id = P100.conversation_id
```

---

# 80. 创建 Quote

API：

```http
POST /posts
```

请求：

```json
{
  "text": "这个观点很值得讨论。",
  "quoted_post_id": "P100"
}
```

后端：

```text
create new Post
quoted_post_id = P100
conversation_id = new_post.id
```

---

# 81. 创建 Repost

API：

```http
POST /posts/P100/repost
```

后端：

```text
check permission
↓
INSERT repost
↓
increment repost_count
↓
emit RepostCreated
```

---

# 82. 取消 Repost

```http
DELETE /posts/P100/repost
```

后端：

```text
DELETE repost relation
↓
decrement counter
↓
emit RepostRemoved
```

---

# 83. Reply API

```http
GET /posts/P100/replies
```

查询：

```text
WHERE reply_to_post_id = P100
```

不是：

```text
WHERE conversation_id = P100
```

因为后者会拿到整个 Thread。

---

# 84. Conversation API

```http
GET /conversations/P100
```

查询：

```text
WHERE conversation_id = P100
```

然后：

```text
构建 Reply Tree
```

---

# 85. Reply Tree API 返回格式

建议：

```json
{
  "root": {
    "id": "P1",
    "text": "原始内容"
  },
  "replies": [
    {
      "id": "P2",
      "parent_id": "P1",
      "replies": [
        {
          "id": "P4",
          "parent_id": "P2"
        }
      ]
    },
    {
      "id": "P3",
      "parent_id": "P1"
    }
  ]
}
```

---

# 86. 前端不要把 Post 类型写死

推荐组件：

```text
<PostCard>
    <PostHeader />
    <PostContent />
    <PostMedia />
    <QuotedPost />
    <PostActions />
</PostCard>
```

然后：

```text
PostCard
 ├── Original
 ├── Reply
 └── Quote
```

Repost：

```text
<RepostWrapper>
    <ReposterHeader />
    <PostCard />
</RepostWrapper>
```

---

# 87. UI 的统一结构

一个 Post 最底层可以统一：

```text
┌──────────────────────────────────┐
│ Avatar   Name @username          │
│                                  │
│ Text content                     │
│                                  │
│ Media                            │
│                                  │
│ ↩ Reply  🔁 Repost  ♥ Like       │
│    Quote     Bookmark  Share     │
└──────────────────────────────────┘
```

---

# 88. Reply UI

```text
Replying to @user

┌──────────────────────────────┐
│ User                         │
│ Original post                │
└──────────────────────────────┘

        ↓

┌──────────────────────────────┐
│ Current user                 │
│ Reply text                   │
└──────────────────────────────┘
```

---

# 89. Quote UI

```text
┌──────────────────────────────┐
│ User                         │
│ 我的评论                     │
│                              │
│ ┌──────────────────────────┐ │
│ │ Original User            │ │
│ │ 原始 Post                │ │
│ └──────────────────────────┘ │
└──────────────────────────────┘
```

---

# 90. Repost UI

```text
↻ User Reposted

┌──────────────────────────────┐
│ Original User                │
│ Original Post                │
└──────────────────────────────┘
```

---

# 91. 四种 UI 对照

```text
Original

User
Text
Actions


Reply

User
Reply text
↳ Replying to A
Actions


Quote

User
Comment
┌──────── Original ────────┐
│ Original Post             │
└───────────────────────────┘
Actions


Repost

↻ User Reposted

Original User
Original Post
Actions
```

---

# 92. Thread UI

```text
User
P1
│
├─ P2
│
├─ P3
│
└─ P4
```

前端：

```text
ThreadView
 ├── ThreadPost(P1)
 ├── ThreadPost(P2)
 ├── ThreadPost(P3)
 └── ThreadPost(P4)
```

---

# 93. 互动动作总表

```text
View
Like
Unlike

Reply
Quote

Repost
Undo Repost

Bookmark
Remove Bookmark

Share

Follow
Unfollow

Mute
Unmute

Block
Unblock

Report

Hide Reply
Unhide Reply
```

---

# 94. 每个动作都应该是独立 Command

推荐：

```text
CreatePost
CreateReply
CreateQuote

CreateRepost
RemoveRepost

LikePost
UnlikePost

BookmarkPost
RemoveBookmark

HideReply
UnhideReply

DeletePost
EditPost
```

而不是：

```text
UpdatePost(type=xxx)
```

把所有业务塞进一个接口。

---

# 95. 权限层

所有动作统一经过：

```text
Authorization Service
```

例如：

```text
canView(user, post)

canReply(user, post)

canRepost(user, post)

canQuote(user, post)

canLike(user, post)

canEdit(user, post)

canDelete(user, post)
```

---

# 96. View Permission

```text
CanView
```

至少判断：

```text
public
protected
blocked
muted
deleted
author relationship
conversation visibility
```

---

# 97. Edit Permission

```text
CanEdit
```

通常：

```text
current_user == post.author
+
post type allowed
+
within edit window
```

并且：

```text
Edit
```

不能修改：

```text
author_id
created_at
conversation_id
reply_to_post_id
```

这些应该属于内容的身份/关系字段。

---

# 98. Delete Permission

```text
CanDelete
```

一般：

```text
current_user == author
```

平台管理员/Moderation 则是另一套权限。

---

# 99. Search

Search 不应该直接：

```sql
SELECT *
FROM posts
WHERE text LIKE '%xxx%'
```

生产环境建议：

```text
Post
 ↓
Search Index
 ↓
OpenSearch / Elasticsearch 等
```

索引：

```text
post_id
author_id
text
language
hashtags
mentions
created_at
engagement
visibility
```

---

# 100. Hashtag

Hashtag 也不要只是字符串。

建议解析：

```text
#AI
```

成为：

```text
Hashtag entity
```

关系：

```text
post_hashtags

post_id
hashtag_id
```

---

# 101. URL / Link Preview

Post：

```text
今天看了 https://example.com
```

建议：

```text
Post
 ↓
URL extraction
 ↓
Link entity
 ↓
Preview metadata
```

例如：

```text
url
domain
title
description
image
```

---

# 102. Poll

Poll 最好独立：

```text
polls
poll_options
poll_votes
```

Post：

```text
post.poll_id
```

但 Poll 与普通 Post 的编辑、互动限制要独立处理。

---

# 103. Analytics

不要把：

```text
view_count
```

当作唯一数据。

建议事件：

```text
PostImpression
PostOpen
PostLike
PostReply
PostRepost
PostQuote
PostBookmark
PostShare
```

例如：

```json
{
  "event": "post.impression",
  "post_id": "P1",
  "viewer_id": "U2",
  "timestamp": "..."
}
```

---

# 104. Feed 推荐

建议把：

```text
Content
```

与：

```text
Recommendation
```

分离。

```text
Post Service
       ↓
Candidate Service
       ↓
Ranking Service
       ↓
Feed
```

---

# 105. 推荐系统候选来源

```text
Following
Reposts
Quotes
Replies
Topics
Search
Trending
Similar Users
Similar Posts
```

然后：

```text
Candidate
 ↓
Deduplication
 ↓
Visibility Filter
 ↓
Safety Filter
 ↓
Ranking
 ↓
Timeline
```

---

# 106. 去重

例如：

```text
A Reposted P1
B Reposted P1
C Reposted P1
```

用户首页不应该出现：

```text
P1
P1
P1
```

而是：

```text
P1
```

并根据：

```text
Reposted by A
Reposted by B
```

决定展示上下文。

---

# 107. 一个 Post 的最终生命周期

```text
Compose
   ↓
Validation
   ↓
Authorization
   ↓
Create Post
   ↓
Post Created Event
   ↓
 ┌──────┬───────┬───────┬──────┐
 ↓      ↓       ↓       ↓
Feed  Search Notification Analytics
 ↓
Timeline
```

---

# 108. Reply 生命周期

```text
User clicks Reply
        ↓
Select parent Post
        ↓
Authorization
        ↓
Create new Post
        ↓
reply_to_post_id
        ↓
conversation_id
        ↓
ReplyCreated
        ↓
Notification
        ↓
Counter++
        ↓
Feed / Conversation
```

---

# 109. Quote 生命周期

```text
User clicks Quote
        ↓
Select original Post
        ↓
Create NEW Post
        ↓
quoted_post_id
        ↓
new conversation_id
        ↓
QuoteCreated
        ↓
Notification
        ↓
quote_count++
        ↓
Feed
```

---

# 110. Repost 生命周期

```text
User clicks Repost
        ↓
Authorization
        ↓
Create RepostRelation
        ↓
repost_count++
        ↓
RepostCreated
        ↓
Feed
        ↓
Notification
```

---

# 111. 删除生命周期

```text
Delete
 ↓
Authorization
 ↓
Soft Delete
 ↓
PostDeleted
 ↓
Remove from Feed
 ↓
Remove from Search
 ↓
invalidate cache
 ↓
recalculate affected counters
 ↓
Repost invalidation
 ↓
Quote reference invalidation
```

---

# 112. 缓存

热点 Post：

```text
post:P100
```

Conversation：

```text
conversation:P100
```

Replies：

```text
post:P100:replies
```

Counters：

```text
post:P100:counters
```

---

# 113. 不要把完整 Reply Tree 永久塞进缓存

因为：

```text
一个热门 Post
可能有百万回复
```

建议：

```text
root
+
top replies
+
cursor pagination
```

---

# 114. 回复分页

不要：

```text
GET /posts/P1/replies
```

一次返回全部。

建议：

```http
GET /posts/P1/replies?sort=top&cursor=xxx
```

或者：

```text
sort:
top
latest
oldest
```

---

# 115. Conversation 分页

由于树结构存在：

```text
A
├── B
│   ├── D
│   └── E
└── C
```

不能只做普通 offset pagination。

推荐：

```text
cursor
depth
parent_id
```

---

# 116. 回复排序维度

至少支持：

```text
Top
Latest
Following
```

Top 可以使用：

```text
engagement
author relationship
reply relevance
quality
recency
```

---

# 117. X 的一个重要产品逻辑

> **内容存在，不等于内容一定会出现在某个用户的 Timeline。**

例如：

```text
Post P1 存在
```

并不意味着：

```text
所有 followers 首页都会看到 P1
```

因为：

```text
Feed ranking
visibility
mute
block
safety
recommendation
```

都会影响展示。

X 官方公开说明也指出，Home Timeline 不只包含关注账号的内容，还可能包含推荐内容；回复也可能基于相关性等因素排序。  
citeturn0search2turn0search1

---

# 118. 最容易犯的 10 个架构错误

## 错误 1

把：

```text
Reply
Comment
Post
```

设计成三套完全不同的内容模型。

正确：

```text
Post + relations
```

---

## 错误 2

把 Repost 复制成新 Post。

正确：

```text
RepostRelation
```

---

## 错误 3

把 Quote 当作 Repost。

正确：

```text
Quote = New Post + quoted_post_id
```

---

## 错误 4

把 Quote 当作 Reply。

正确：

```text
Reply → conversation
Quote → independent Post + reference
```

---

## 错误 5

只有 parent_id，没有 conversation_id。

正确：

```text
parent_post_id
+
conversation_id
```

---

## 错误 6

reply_count 统计整个 conversation。

正确：

```text
direct replies
```

---

## 错误 7

隐藏 Reply 直接删除。

正确：

```text
moderation visibility
```

---

## 错误 8

首页：

```sql
ORDER BY created_at DESC
```

正确：

```text
Candidate → Filter → Rank → Feed
```

---

## 错误 9

把 Like/Repost/Bookmark 做成 Post 数组。

正确：

```text
Relation tables
```

---

## 错误 10

删除 Post 就物理删除全部关系。

正确：

```text
Soft Delete
+
Event
+
Downstream cleanup
```

---

# 119. 最终推荐模型

如果要自己实现一个类似 X 的系统，我建议最终简化为：

```text
USER
 │
 ├── follows
 ├── blocks
 └── mutes
 │
 ▼
POST
 │
 ├── author
 ├── text
 ├── media
 │
 ├── reply_to_post_id
 │
 ├── conversation_id
 │
 └── quoted_post_id
 │
 ├──────────────┬──────────────┐
 ▼              ▼              ▼
LIKE          REPOST          BOOKMARK
 │              │
 ▼              ▼
User           User
```

---

# 120. 最核心的抽象

最终只需要记住下面这张图：

```text
                         ┌─────────────┐
                         │    USER     │
                         └──────┬──────┘
                                │
                              creates
                                │
                                ▼
                         ┌─────────────┐
                         │    POST     │
                         └──────┬──────┘
                                │
        ┌───────────────────────┼────────────────────────┐
        │                       │                        │
        ▼                       ▼                        ▼
   reply_to_post          quoted_post              original
        │                       │
        ▼                       ▼
   Reply Post               Quote Post


                         POST
                          ▲
                          │
                       reposts
                          │
                          ▼
                         USER


POST
 │
 ├── likes
 ├── bookmarks
 ├── mentions
 ├── media
 └── hashtags
```

---

# 121. 一句话定义四者

```text
发帖：
创建一个独立 Post。

回复：
创建一个 Post，并把 reply_to_post_id 指向另一条 Post。

引用转发：
创建一个 Post，并把 quoted_post_id 指向另一条 Post。

普通转发：
不复制 Post，只创建 User → Post 的 RepostRelation。
```

---

# 122. 对于你自己的项目，推荐直接采用的最终设计

如果你要实现一个“类似 X 的内容社区”，不要直接照搬 X 的 UI，而应该采用下面这个内核：

```text
                    Content Layer
                         │
                         ▼
                       Post
                         │
       ┌─────────────────┼──────────────────┐
       │                 │                  │
       ▼                 ▼                  ▼
     Reply              Quote             Media
       │                 │
       ▼                 ▼
 Conversation         Reference


                    Interaction Layer
                         │
       ┌─────────┬───────┼────────┬─────────┐
       ▼         ▼       ▼        ▼         ▼
      Like     Repost  Bookmark  Share    Mention


                     Social Layer
                         │
       ┌─────────┬───────┼────────┬─────────┐
       ▼         ▼       ▼        ▼         ▼
     Follow    Block    Mute    Report   Notification


                      Feed Layer
                         │
                         ▼
                  Candidate Generation
                         ↓
                      Filtering
                         ↓
                       Ranking
                         ↓
                       Feed
```

这个架构比单纯做：

```text
发帖
评论
回复
转发
```

四个功能模块要稳健得多。

---

# 123. 最终数据关系

可以压缩成：

```text
User
 │
 ├──────────────┐
 │              │
 ▼              ▼
Post          Relation
 │              │
 │              ├── Like
 │              ├── Repost
 │              ├── Bookmark
 │              ├── Follow
 │              ├── Block
 │              └── Mute
 │
 ├── reply_to_post_id ──────┐
 │                           │
 ├── conversation_id         │
 │                           ▼
 │                       Conversation
 │
 └── quoted_post_id ────────┐
                            │
                            ▼
                       Quoted Post
```

**这就是 X 的“发帖—回复—转发—引用转发”体系最重要的结构逻辑。**

---

# 124. 如果作为软件工程规格，建议进一步拆成这些模块

```text
01-content/
    post
    post_version
    media
    hashtag
    mention

02-conversation/
    reply
    conversation
    thread

03-engagement/
    like
    repost
    quote
    bookmark
    share

04-social/
    follow
    block
    mute

05-feed/
    home_timeline
    profile_timeline
    conversation_feed
    ranking

06-notification/
    notification
    mention_notification
    reply_notification
    repost_notification
    quote_notification

07-moderation/
    report
    hide_reply
    content_visibility
    safety

08-search/
    post_index
    user_index
    hashtag_index

09-analytics/
    impression
    engagement_event

10-api/
    post_api
    reply_api
    repost_api
    quote_api
    timeline_api
```

---

# 125. 最终工程原则

如果以后把这个模型用于自己的项目，建议遵守以下原则：

1. **Post 是统一内容原子。**
2. **Reply 是 Post + parent relationship。**
3. **Quote 是 Post + referenced Post。**
4. **Repost 是 User + Post relationship，而不是复制 Post。**
5. **Conversation 与直接 Reply 父子关系必须分开。**
6. **TimelineItem 与 Post 必须分开。**
7. **Like/Repost/Bookmark 等互动全部关系化。**
8. **Counter 是缓存/派生数据，不是真实数据源。**
9. **Visibility、Moderation、Authorization 独立设计。**
10. **删除优先采用软删除 + Event 驱动。**
11. **Feed 不等于数据库中的 Post 列表。**
12. **Reply Tree 必须支持分页和排序。**
13. **Quote 不自动加入原 Post 的 conversation。**
14. **Mention 不等于 Reply。**
15. **Thread 可以由 Reply + Conversation 实现，不必另造一套内容模型。**
16. **所有核心行为都应该可以事件化。**
17. **Search、Feed、Notification、Analytics 不应该与 Post 表强耦合。**
18. **前端 PostCard 应统一渲染 Original / Reply / Quote。**
19. **Repost 应作为展示包装层，而不是复制内容。**
20. **真正的核心不是“发帖功能”，而是“Post + Relationship + Feed”三层架构。**
