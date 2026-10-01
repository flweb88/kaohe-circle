# X（原 Twitter）用户关系网、消息、私信与通知：结构、逻辑与内容完整说明

> **用途**：本文把 X 的「用户关系 → 内容互动 → 消息/私信 → 通知」拆成可用于产品设计、数据库设计、后端事件系统和客户端 UI 的结构化模型。
>
> **资料时间**：2026-10-01。功能以 X 当前公开帮助中心资料为基础；X 的产品功能会持续变化，因此本文将「公开确认的产品行为」与「工程上的合理抽象」区分开。
>
> **核心思想**：
>
> ```text
> 用户 User
>   │
>   ├── 关系 Graph
>   │    ├── Follow / Follower
>   │    ├── Block
>   │    ├── Mute
>   │    ├── List
>   │    ├── Subscribe
>   │    └── Conversation participant
>   │
>   ├── 内容 Content
>   │    ├── Post
>   │    ├── Reply
>   │    ├── Repost
>   │    ├── Quote Post
>   │    └── Mention
>   │
>   ├── 私域通信 Messaging
>   │    ├── DM Request
>   │    ├── 1:1 Conversation
>   │    ├── Group Conversation
>   │    └── Chat / encrypted messaging
>   │
>   └── 通知 Notification
>        ├── In-app Notifications
>        ├── Push
>        ├── Email
>        └── Web / Browser notification
> ```

---

# 1. 总体架构：X 不是一个单一的「好友关系」系统

X 的关系模型更接近一个**多类型、有方向、可叠加的社会关系图谱**。

最重要的区别是：

```text
A follows B
≠
B follows A
≠
A subscribes to B
≠
A is on B's List
≠
A muted B
≠
A blocked B
≠
A and B have a DM conversation
```

因此，一个用户与另一个用户之间可以同时存在多个关系。

例如：

```text
A ──follow──────→ B
A ←─follower──── B
A ──subscribe───→ B
A ──mute─────────→ B
A ──DM─────────── B
A ──List member──→ B
```

这些关系并不是互相排斥的。

尤其需要注意：

- **Follow 是内容订阅关系**
- **Follower 是被订阅关系**
- **Mute 是内容过滤关系**
- **Block 是交互权限限制关系**
- **DM 是通信关系**
- **List 是人为建立的分类关系**
- **Subscription 是付费/创作者关系**

X 官方对 Following 的定义是：关注某人意味着订阅其帖子，其更新会进入 Home timeline，并且对方可以向你发送 DM。citeturn1search3


# 2. User 用户对象

## 2.1 用户核心对象

可以抽象为：

```text
User
├── id
├── username
├── display_name
├── avatar
├── bio
├── location
├── website
├── created_at
├── account_status
├── verification_status
├── privacy_status
├── follower_count
├── following_count
├── post_count
└── profile_settings
```

其中：

### account_status

```text
ACTIVE
LOCKED
SUSPENDED
DEACTIVATED
```

### privacy_status

```text
PUBLIC
PROTECTED
```

Protected account 会改变 Follow、内容可见性等权限逻辑。

---

# 3. 用户关系网 Graph

## 3.1 Follow

核心关系：

```text
Follower ──FOLLOW──→ Followee
```

例如：

```text
Alice ──follow──→ Bob
```

意味着：

```text
Alice
  ↓
订阅 Bob 的内容
```

主要结果：

```text
Bob 发布 Post
      ↓
Alice 的 Following timeline
```

但不是所有关系都意味着内容一定直接展示，因为 X 还有 For You、推荐、静音、过滤等机制。

---

# 3.2 Following 与 Follower

从 Alice 角度：

```text
Following
= Alice 主动关注的人
```

从 Bob 角度：

```text
Followers
= 关注 Bob 的人
```

关系是有方向的。

```text
A → B
```

并不自动产生：

```text
B → A
```

---

# 3.3 Mutual / 双向关注

如果：

```text
A → B
B → A
```

则：

```text
A ↔ B
```

可以称为 mutual / 双向关注。

工程上不要把它存成一个单独的关系类型，而应该由两个 Follow edge 推导：

```text
follow(A,B) = true
follow(B,A) = true

=> mutual(A,B) = true
```

这样数据结构更简单。

---

# 3.4 Protected Account

如果 B 是 Protected：

```text
A ──request──→ B
```

而不是直接：

```text
A ──follow──→ B
```

B 可以：

```text
Accept
Reject
```

只有批准后：

```text
A → B
```

才正式成为 Follow。

---

# 3.5 Unfollow

```text
A → B
```

删除该 edge：

```text
delete Follow(A,B)
```

注意：

```text
unfollow ≠ block
```

Unfollow 只是停止订阅。

---

# 3.6 Remove Follower

另一种关系变化：

```text
B 被 A follow
```

A 可以移除 B，使：

```text
B → A
```

消失。

它与 Block 不同：

```text
Remove follower
=
删除关系

Block
=
删除关系 + 限制未来交互
```

---

# 4. Block：强限制关系

Block 是一种强制隔离关系：

```text
A ──BLOCK──→ B
```

主要效果包括：

```text
B 不能 follow A
B 不能 DM A
B 不能对 A 的公开帖子进行正常互动
A 不再收到 B 的通知
```

如果双方原本互相关注，Block 会使 Follow 关系解除。

X 官方说明 Block 会限制关注、私信和互动；公开账号被 Block 后，对方仍可能看到公开帖子，但不能正常 Like、Reply、Repost 等。citeturn1search1

工程上可以理解为：

```text
BLOCK
   ↓
relationship override
   ↓
禁止多个 interaction capability
```

而不是简单删除一个 Follow。

---

# 5. Mute：弱过滤关系

Mute：

```text
A ──MUTE──→ B
```

与 Block 最大的区别：

```text
A 仍然可以 Follow B
B 仍然可以 Follow A
B 不知道 A mute 了自己
```

主要作用是：

```text
过滤 B 的内容
```

X 官方明确说明，Mute 不会导致 Unfollow 或 Block；被 mute 的账号也不会知道。citeturn1search2

因此：

```text
Mute
≠
Relationship deletion

Mute
=
Content filtering
```

---

# 6. Mute Words / Hashtags

Mute 不仅可以针对 User，也可以针对：

```text
word
phrase
username
emoji
hashtag
```

例如：

```text
mute("NBA")
mute("#Trump")
mute("@someuser")
```

这些过滤条件可以作用于：

```text
Home
Notifications
Replies
Push
SMS
Email
```

但搜索结果仍可能显示相关内容。citeturn1search12

---

# 7. List：人工建立的第二关系网

List 是非常重要、但经常被忽略的一层关系。

```text
List
├── id
├── owner
├── name
├── description
├── privacy
└── members[]
```

例如：

```text
List: AI Researchers

members:
├── @OpenAI
├── @GoogleDeepMind
├── @Anthropic
└── @xAI
```

用户可以：

```text
Follow List
```

而不需要 Follow List 中的每一个账号。

X 官方说明可以直接关注其他人的 List，而不关注其中的个人账号；List 本身形成独立内容时间线。citeturn1search14

因此关系网实际上是：

```text
User Graph
+
List Graph
```

---

# 8. Subscription：付费创作者关系

Subscription：

```text
User A
   │
   └──SUBSCRIBE──→ Creator B
```

它不是普通 Follow。

可能包含：

```text
exclusive content
exclusive previews
perks
subscriber badge
```

订阅者回复创作者时，可以显示 Subscriber badge。citeturn1search13

因此：

```text
Follow
= 内容订阅

Subscribe
= 付费/会员关系
```

二者应该在数据库中分开。


# 9. Content 内容对象

X 的关系网最终围绕 Content 运行。

核心对象：

```text
Post
├── original post
├── reply
├── repost
├── quote post
└── thread post
```

---

# 10. Original Post

```text
User
  │
  └──creates──→ Post
```

Post：

```text
Post
├── id
├── author_id
├── text
├── media[]
├── created_at
├── visibility
├── reply_settings
├── language
└── conversation_id
```

---

# 11. Reply

Reply：

```text
A
 │
 └──reply──→ Post B
```

形成：

```text
Conversation
└── root post
    ├── reply
    │   ├── reply
    │   └── reply
    └── reply
```

X 的 Conversation 模型会记录：

```text
root
parent
reply
participants
```

X 官方说明，回复和 Mention 会进入 Notifications；点击通知可以进入完整 conversation 并看到参与者。citeturn0search6

---

# 12. Mention

Mention：

```text
Post
  │
  └──mentions──→ @Alice
```

例如：

```text
今天和 @Alice 讨论 AI。
```

它不等同于 Reply。

可以：

```text
Post A
└── mention Alice
```

而没有：

```text
reply_to
```

因此数据库最好分开：

```text
ReplyEdge
MentionEdge
```

---

# 13. Repost

```text
A
 │
 └──repost──→ Post B
```

它不是复制创建一个新的 Original Post。

应该理解为：

```text
Repost
├── actor = A
└── target_post = B
```

---

# 14. Quote Post

Quote：

```text
A
 │
 └──creates──→ Post C
                    │
                    └──quotes──→ Post B
```

因此：

```text
Repost
=
纯传播

Quote
=
传播 + 新内容
```

---

# 15. Like

Like：

```text
A
 │
 └──LIKE──→ Post B
```

Like 是：

```text
User → Content
```

的一种互动 edge。

它同时产生两个方向的数据：

```text
Post B
└── like_count += 1

User A
└── liked_posts += B
```

并且可能产生：

```text
Notification
```

---

# 16. Bookmark

Bookmark：

```text
A
 │
 └──BOOKMARK──→ Post B
```

与 Like 不同：

```text
Like
= 对外可见的互动信号

Bookmark
= 用户自己的保存动作
```

Bookmark 通常不作为对作者公开展示的互动关系。

---

# 17. Follow → Content → Interaction → Notification

这是 X 的核心链路：

```text
User
 │
 └──Follow──→ User B
                  │
                  └──Post
                       │
                       ├──Like
                       ├──Reply
                       ├──Repost
                       ├──Quote
                       └──Mention
                              │
                              ↓
                         Notification
```

也就是说：

```text
关系网
    ↓
内容分发
    ↓
内容互动
    ↓
事件
    ↓
通知
```


# 18. Timeline 与消息流

X 至少可以抽象成两种 Home 流：

```text
For You
Following
```

## Following

核心逻辑：

```text
Followed accounts
      ↓
their posts
      ↓
Following timeline
```

X 官方说明 Following timeline 主要展示你关注账号的帖子。citeturn1search7

---

# 19. For You

For You：

```text
Following content
+
Topics
+
Recommendations
+
Popular content
+
Network signals
```

因此：

```text
For You ≠ Following
```

推荐内容可能来自：

```text
你没有 Follow 的账号
```

X 官方说明 For You 会结合已关注账号、Topics、推荐内容以及网络互动等信号。citeturn1search7

---

# 20. 消息流与通知流必须分开

这是产品设计中最重要的区别之一：

```text
Timeline
=
内容流

Notifications
=
事件流

Messages
=
通信流
```

三者不能混成一个 Feed。

例如：

```text
Bob 发了一条 Post
```

可能进入：

```text
Alice Following timeline
```

但：

```text
Bob Like 了 Alice 的 Post
```

进入：

```text
Alice Notifications
```

而：

```text
Bob DM Alice
```

进入：

```text
Alice Messages
```

---

# 21. Notifications 通知系统

X Notifications timeline 用于显示其他人与你之间的互动。

公开帮助中心列出的典型通知包括：

```text
New follower
Like
Repost
Reply
Mention
New List follower
```

并提供：

```text
All
Mentions
Verified
```

等视图。citeturn1search0

---

# 22. Notification Event 模型

工程上建议抽象成：

```text
NotificationEvent
├── id
├── recipient_id
├── actor_id
├── event_type
├── target_type
├── target_id
├── conversation_id
├── created_at
├── read_at
├── delivered_at
└── metadata
```

例如：

```json
{
  "recipient_id": "Alice",
  "actor_id": "Bob",
  "event_type": "LIKE",
  "target_type": "POST",
  "target_id": "P123"
}
```

---

# 23. Notification Event Types

可以抽象为：

```text
FOLLOW
FOLLOW_REQUEST
FOLLOW_ACCEPTED

LIKE
REPOST
QUOTE
REPLY
MENTION

LIST_FOLLOW
SUBSCRIPTION

DM
DM_REQUEST

SYSTEM
SECURITY
ACCOUNT
```

实际 X 的具体通知类型会随产品版本变化，因此工程实现最好采用可扩展 enum。

---

# 24. 通知不是一个渠道

一个 Event 可以进入多个 Delivery Channel：

```text
Event
 │
 ├── In-App Notification
 ├── Push Notification
 ├── Email
 └── Web/Browser Notification
```

例如：

```text
Bob follows Alice
       ↓
FOLLOW_EVENT
       ↓
 ┌─────┼────────┐
 ↓     ↓        ↓
InApp Push     Email
```

X 官方说明 Web/Browser notifications 可以覆盖 DM、Follow、Like、Reply、Mention、Repost 等事件。citeturn1search8

---

# 25. Notification Preference

通知系统应该包含：

```text
NotificationPreference
├── push
├── email
├── web
├── likes
├── replies
├── mentions
├── follows
├── reposts
├── messages
└── recommendations
```

因此：

```text
Event != Notification Delivery
```

而应该是：

```text
Event
 ↓
Preference check
 ↓
Eligibility check
 ↓
Channel routing
 ↓
Delivery
```

---

# 26. Notification Filtering

通知可能受到：

```text
Block
Mute user
Mute word
Quality filter
Privacy
Notification preferences
```

共同影响。

例如：

```text
Bob mentions Alice
       ↓
Mention Event
       ↓
Mute / Block / Filter?
       ↓
是否产生最终通知
```

所以 Notification 不是简单：

```text
if mention => notify
```

而是：

```text
event
→ policy
→ filter
→ delivery
```


# 27. DM 私信系统

DM 是 X 的独立通信系统。

核心结构：

```text
Conversation
├── participants
├── messages
├── settings
├── notification state
└── security state
```

---

# 28. 1:1 DM

```text
Conversation
├── Alice
└── Bob
```

消息：

```text
Message 1
Alice → Bob

Message 2
Bob → Alice

Message 3
Alice → Bob
```

---

# 29. Group DM

```text
Conversation
├── Alice
├── Bob
├── Carol
└── Dave
```

群聊中参与者看到群内消息。

X 当前公开帮助中心说明，群聊最多可包含 256 人；参与者可以添加成员，新加入成员不会看到加入前的历史消息。citeturn0search0

因此工程模型最好是：

```text
Conversation
├── participant membership
│
└── Message
```

而不是把 participant 直接写死在 Message 中。

---

# 30. Conversation Membership

```text
ConversationMember
├── conversation_id
├── user_id
├── joined_at
├── left_at
├── role
├── notification_setting
└── last_read_message_id
```

这样可以实现：

```text
Join
Leave
Add member
Remove member
Snooze
Read state
```

---

# 31. DM Request

这是非常关键的一层。

如果：

```text
A 不 Follow B
```

仍可能存在：

```text
A → DM request → B
```

是否允许取决于：

```text
B 的 DM settings
A/B verification
既有会话
特殊关系
```

X 当前说明，未关注对象的消息可以进入 Requests；接受后才进入正常 Inbox。citeturn0search0

---

# 32. DM Request 生命周期

```text
SENDER
  │
  │ send
  ↓
REQUEST
  │
  ├── Accept
  │      ↓
  │   ACTIVE CHAT
  │
  ├── Delete
  │      ↓
  │   REMOVED
  │
  ├── Block
  │      ↓
  │   BLOCKED
  │
  └── Report
         ↓
       REVIEW
```

重要：

```text
Request
≠
Active Conversation
```

---

# 33. DM 权限矩阵

可抽象：

| 条件 | 是否可能 DM |
|---|---|
| 双方存在正常会话 | 是 |
| 对方允许任何人发 DM | 是 |
| 对方允许 Verified 用户 | 视认证状态 |
| 对方允许 Subscriber | 视订阅关系 |
| Premium Business/Organization 特殊关系 | 视资格 |
| 被 Block | 否 |
| DM Block | 否 |

X 当前帮助中心明确列出了上述多种 DM 进入条件。citeturn0search0turn0search5

---

# 34. DM Block

X 还有一个值得单独建模的概念：

```text
DM Block
```

它不同于完整 Block。

可以：

```text
禁止 A 与 B 私信
```

但：

```text
仍允许公共 Post 互动
```

X 官方说明 DM block 不影响群聊中的双方可见性，也不阻止公共帖子中的 Like、Reply、Mention 等互动。citeturn0search5

因此：

```text
Global Block
    ↓
全局关系限制

DM Block
    ↓
仅限制 private communication
```

---

# 35. DM Message

核心结构：

```text
Message
├── id
├── conversation_id
├── sender_id
├── body
├── attachments[]
├── reply_to
├── created_at
├── edited_at
├── deleted_at
└── metadata
```

---

# 36. DM 支持的内容

公开资料显示 DM 可以包含：

```text
Text
Photo
Video
GIF
Emoji
Shared Post
Link
Reaction
```

此外当前 X 已有 DM 编辑能力。citeturn0search0

---

# 37. DM Read Receipt

DM 可以存在：

```text
sent
delivered
read
```

状态：

```text
Message
  ↓
sent
  ↓
delivered
  ↓
read
```

X 当前支持 Read Receipts，并允许关闭；关闭后自己也不能看到其他人的 read receipts。citeturn0search0

---

# 38. DM 删除

一个非常重要的产品逻辑：

```text
Delete message
```

并不等于：

```text
全局删除
```

X 官方说明，删除 DM 或整个 conversation 后，只会从执行删除的账户中删除；其他参与者仍可能看到对应消息。citeturn0search0

因此：

```text
User-local deletion
≠
Global deletion
```

数据库最好考虑：

```text
MessageVisibility
```

或：

```text
MessageUserState
```

---

# 39. DM Snooze

Conversation 可以：

```text
Snooze
```

例如：

```text
1 hour
8 hours
1 week
Forever
```

Snooze：

```text
保留 Message
+
关闭该会话的通知
```

而不是：

```text
删除消息
```

X 官方明确说明 Snooze 后仍会收到新消息，只是不逐条收到通知。citeturn0search0

---

# 40. DM Search

DM 可以搜索：

```text
keywords
names
messages
groups
people
```

所以 Message 本身需要支持：

```text
full-text search
```

或建立：

```text
MessageSearchIndex
```

X 当前帮助中心确认 DM 支持按关键词和姓名搜索。citeturn0search0


# 41. X Chat：加密私信层

当前 X 还存在 Chat 这一更偏安全通信的产品形态。

公开资料显示，X Chat 使用端到端加密（E2EE）设计：

```text
Sender Device
      │
      │ encrypt
      ↓
X infrastructure
      │
      │ encrypted
      ↓
Recipient Device
      │
      │ decrypt
      ↓
Plaintext
```

X 的公开说明还描述了：

```text
User public/private key pair
+
Per-conversation key
```

并说明消息、链接、reaction 在离开发送设备前加密，并在 X 基础设施存储期间保持加密。citeturn0search3

---

# 42. Chat Request

Chat 也存在 Request 层：

```text
Request
   ↓
Accept
   ↓
Encrypted conversation
```

公开资料说明，Chat 的 message request 在接受前是不加密的；接受后双方会获知会话进入加密状态。Grok 还可用于把 request 分类到 Priority / Hidden inbox。citeturn0search3

因此：

```text
DM Request
Chat Request
```

在产品概念上相关，但在工程上最好保持：

```text
communication_mode
=
LEGACY_DM
CHAT
```

---

# 43. Group Call / Communication Extension

DM 系统还可以扩展：

```text
Conversation
   │
   ├── Text
   ├── Media
   ├── Reaction
   ├── Voice call
   └── Video call
```

当前 X 已支持群组音视频通话功能，但官方资料注明当前群组通话尚未端到端加密。citeturn0search4


# 44. 通知系统完整事件流

推荐把 X 式通知理解成：

```text
                    ┌──────────────┐
                    │   USER       │
                    └──────┬───────┘
                           │
                  interaction
                           ↓
                 ┌─────────────────┐
                 │ Interaction     │
                 │ Event           │
                 └────────┬────────┘
                          ↓
                 ┌─────────────────┐
                 │ Permission /    │
                 │ Privacy Check   │
                 └────────┬────────┘
                          ↓
                 ┌─────────────────┐
                 │ Filter / Mute / │
                 │ Block / Quality │
                 └────────┬────────┘
                          ↓
                 ┌─────────────────┐
                 │ Notification    │
                 │ Event           │
                 └────────┬────────┘
                          ↓
              ┌───────────┼────────────┐
              ↓           ↓            ↓
           In-App        Push        Email
```

这比：

```text
if event:
   send_notification()
```

复杂得多。

---

# 45. 典型事件：Follow

```text
Alice → Follow → Bob
```

系统：

```text
1. Create FollowEdge
2. Update Bob follower count
3. Generate FOLLOW event
4. Check Bob notification preference
5. Check Alice status
6. Create notification
7. Push if enabled
8. Update notification timeline
```

---

# 46. 典型事件：Like

```text
Alice → Like → Bob's Post
```

：

```text
LikeEdge
     ↓
LIKE_EVENT
     ↓
Notification
```

如果 Bob 关闭相关通知：

```text
LIKE_EVENT
   ↓
no push
```

但 Like 本身仍然存在。

所以：

```text
Notification preference
不会删除 interaction
```

---

# 47. 典型事件：Mention

```text
Alice creates Post
      │
      └──mentions──→ Bob
                         │
                         ↓
                    MENTION_EVENT
                         │
             ┌───────────┼───────────┐
             ↓           ↓           ↓
          In-App        Push        Email
```

X 官方说明 Mention 可以出现在 Notifications timeline、Push 等通知渠道，具体取决于设置。citeturn0search2turn1search0

---

# 48. 典型事件：Reply

```text
Alice
  │
  └──reply──→ Bob's Post
                  ↓
             REPLY_EVENT
                  ↓
              Bob notified
```

同时：

```text
Conversation
```

也发生更新。

所以一个 Reply 同时属于：

```text
Content graph
+
Conversation graph
+
Notification graph
```

---

# 49. 典型事件：DM

```text
Alice
  │
  └──DM──→ Bob
             ↓
        Message Created
             ↓
      Conversation Updated
             ↓
      Notification Event
             ↓
      Push / In-App
```

但是：

```text
Snooze = suppress notification
```

而：

```text
Request = separate inbox
```

所以 DM 的状态比普通 Notification 更复杂。


# 50. 用户关系与通信权限：统一模型

建议建立一个：

```text
Relationship Policy Engine
```

输入：

```text
actor
target
action
context
```

例如：

```text
actor = Alice
target = Bob
action = SEND_DM
```

输出：

```text
ALLOW
DENY
REQUEST
```

---

# 51. Policy 判断顺序

建议：

```text
1. Account status
2. Block
3. DM Block
4. Privacy
5. Follow relationship
6. Verification
7. Subscription
8. Conversation history
9. User settings
10. Content safety
11. Quality filter
12. Final decision
```

例如：

```text
Alice → DM → Bob

Bob blocked Alice
       ↓
DENY
```

即使：

```text
Alice follows Bob
Alice is Verified
Alice is Subscriber
```

也不应该绕过 Block。

---

# 52. 能力矩阵

可以建立统一 capability：

```text
VIEW_PROFILE
VIEW_POST
FOLLOW
FOLLOW_REQUEST
LIKE
REPLY
REPOST
QUOTE
MENTION
DM
DM_REQUEST
ADD_TO_GROUP
READ_DM
SEND_DM
CALL
SUBSCRIBE
```

然后：

```text
Relationship
+
Privacy
+
Content state
+
Account state
```

共同决定 capability。

---

# 53. Relationship State Machine

用户关系可以抽象：

```text
NONE
 │
 ├── FOLLOW ──────────→ FOLLOWING
 │
 ├── REQUEST ─────────→ PENDING
 │                         │
 │                         └── ACCEPT → FOLLOWING
 │
 ├── MUTE ────────────→ MUTED
 │
 ├── BLOCK ───────────→ BLOCKED
 │
 └── DM ──────────────→ DM_CONNECTED
```

但实际系统中不要把这些全部做成 mutually-exclusive state。

更合理：

```text
FollowEdge
MuteEdge
BlockEdge
DMEdge
SubscribeEdge
ListMembership
```

每条边独立存在。

---

# 54. 为什么不能使用单一 Relationship 字段

错误：

```text
relationship = "FOLLOWING"
```

因为现实中可能：

```text
FOLLOWING = true
MUTED = true
SUBSCRIBED = true
DM_CONNECTED = true
```

甚至：

```text
FOLLOWING = true
DM_BLOCKED = true
```

因此：

```text
Relationship
=
Multi-dimensional graph
```

而不是：

```text
Single enum
```


# 55. 数据库建议

## User

```sql
users
------
id
username
display_name
avatar_url
bio
privacy
status
created_at
```

## Follow

```sql
follows
------
follower_id
followee_id
created_at

PRIMARY KEY (follower_id, followee_id)
```

## Block

```sql
blocks
------
blocker_id
blocked_id
created_at
```

## Mute

```sql
mutes
------
muter_id
muted_id
created_at
```

## Subscription

```sql
subscriptions
------
subscriber_id
creator_id
created_at
status
```

## Lists

```sql
lists
------
id
owner_id
name
description
privacy
```

```sql
list_members
------
list_id
user_id
created_at
```

---

# 56. Post

```sql
posts
------
id
author_id
text
visibility
conversation_id
parent_post_id
created_at
deleted_at
```

---

# 57. Post interactions

```sql
post_likes
------
user_id
post_id
created_at
```

```sql
reposts
------
user_id
post_id
created_at
```

```sql
bookmarks
------
user_id
post_id
created_at
```

---

# 58. Mentions

```sql
mentions
------
post_id
mentioned_user_id
created_at
```

---

# 59. Conversations

```sql
conversations
------
id
type
created_by
created_at
updated_at
```

type：

```text
DIRECT
GROUP
CHAT
```

---

# 60. Conversation Members

```sql
conversation_members
------
conversation_id
user_id
role
joined_at
left_at
last_read_message_id
notification_snoozed_until
```

---

# 61. Messages

```sql
messages
------
id
conversation_id
sender_id
body
reply_to_message_id
created_at
edited_at
deleted_at
```

---

# 62. Message attachments

```sql
message_attachments
------
id
message_id
type
url
metadata
```

type：

```text
IMAGE
VIDEO
GIF
POST
LINK
FILE
```

---

# 63. Message Requests

```sql
message_requests
------
id
conversation_id
sender_id
recipient_id
status
created_at
accepted_at
```

status：

```text
PENDING
ACCEPTED
DELETED
BLOCKED
REPORTED
```

---

# 64. Notifications

```sql
notifications
------
id
recipient_id
actor_id
event_type
target_type
target_id
conversation_id
created_at
read_at
```

---

# 65. Notification delivery

不要把 Push 状态写在 notifications 主表里。

应该：

```sql
notification_deliveries
------
id
notification_id
channel
status
attempts
sent_at
failed_at
```

channel：

```text
IN_APP
PUSH
EMAIL
WEB
```

这样：

```text
Notification
      │
      ├── PUSH = SENT
      ├── EMAIL = DISABLED
      └── IN_APP = READ
```

非常清晰。


# 66. 推荐的事件总线

整个系统可以通过 Event Bus 串起来：

```text
                    EVENT BUS
                       │
      ┌────────────────┼────────────────┐
      ↓                ↓                ↓
 Relationship       Content          Messaging
 Service            Service          Service
      │                │                │
      └────────────────┼────────────────┘
                       ↓
                Notification
                   Service
                       │
              ┌────────┼─────────┐
              ↓        ↓         ↓
            In-App    Push      Email
```

---

# 67. Event Schema

统一事件：

```json
{
  "event_id": "evt_123",
  "event_type": "POST_LIKED",
  "actor_id": "user_A",
  "target_id": "post_B",
  "target_type": "POST",
  "recipient_id": "user_B",
  "created_at": "2026-10-01T10:00:00Z",
  "metadata": {}
}
```

---

# 68. Event 与 Notification 的区别

这是整个系统最容易设计错的地方：

```text
Event
=
事实发生了

Notification
=
系统决定告诉谁
```

例如：

```text
Alice liked Bob's Post
```

事实：

```text
LIKE_EVENT
```

但 Bob 是否收到：

```text
Push
Email
In-App
```

由：

```text
Notification Policy
```

决定。

---

# 69. Notification 去重

高频事件可能需要合并：

```text
Alice liked
Bob liked
Carol liked
Dave liked
```

可以展示成：

```text
Alice, Bob and 2 others liked your post.
```

数据库仍然可以保存：

```text
4 LIKE events
```

展示层进行 aggregation：

```text
4 events
↓
1 notification cluster
```

因此：

```text
Event storage
≠
Notification presentation
```

---

# 70. Notification 聚合模型

建议：

```text
NotificationCluster
├── cluster_key
├── recipient_id
├── event_type
├── target_id
├── actor_count
├── latest_event_at
└── event_ids[]
```

例如：

```text
LIKE + POST_123
```

作为 cluster key。

---

# 71. Read State

Notification 要独立记录：

```text
unread
read
```

而 DM 要独立记录：

```text
last_read_message
```

Timeline 也不应该复用这套状态。

即：

```text
Notification read state
≠
DM read state
≠
Post seen state
```

---

# 72. 客户端 Badge

App 上常见：

```text
Notifications badge = unread notifications
Messages badge = unread messages / requests
```

二者不能混为：

```text
total_unread
```

推荐：

```text
unread_notifications
unread_messages
unread_requests
```

分别维护。

---

# 73. 端到端完整链路

## Case A：Follow

```text
Alice
 ↓
Follow Bob
 ↓
FollowEdge
 ↓
FOLLOW_EVENT
 ↓
Notification Policy
 ↓
Notification
 ↓
In-App
 ↓
Push / Email
```

---

## Case B：Like

```text
Alice
 ↓
Like Bob's Post
 ↓
LikeEdge
 ↓
LIKE_EVENT
 ↓
Notification
 ↓
Aggregation
 ↓
Bob Notifications
```

---

## Case C：Reply

```text
Alice
 ↓
Reply Bob's Post
 ↓
Reply
 ↓
Conversation update
 ↓
REPLY_EVENT
 ↓
Notification
 ↓
Bob
```

---

## Case D：Mention

```text
Alice
 ↓
Post @Bob
 ↓
MentionEdge
 ↓
MENTION_EVENT
 ↓
Notification
 ↓
Bob
```

---

## Case E：DM

```text
Alice
 ↓
Send DM
 ↓
Permission Engine
 ↓
 ├── DENY
 ├── REQUEST
 └── ALLOW
       ↓
    Message
       ↓
 Conversation
       ↓
 Notification
       ↓
 Push / In-App
```

---

# 74. 一个非常关键的设计原则

X 式产品不能简单理解为：

```text
用户
 ↓
发内容
 ↓
别人看到
```

真正的系统应该是：

```text
                 ┌───────────────┐
                 │     USER      │
                 └───────┬───────┘
                         │
              ┌──────────┼──────────┐
              ↓          ↓          ↓
          Relation     Content    Messaging
              │          │          │
              ↓          ↓          ↓
           Graph      Interaction Conversation
              │          │          │
              └──────────┼──────────┘
                         ↓
                       Event
                         ↓
                  Policy / Filter
                         ↓
                  Notification
                         ↓
             ┌───────────┼───────────┐
             ↓           ↓           ↓
           In-App       Push       Email
```

这才是完整的产品逻辑。


# 75. 对软件工程项目最值得借鉴的模块划分

如果要开发一个类似 X 的产品，建议不要按照页面划分：

```text
错误：
HomePage
ProfilePage
MessagePage
NotificationPage
```

而应该按照领域划分：

```text
Identity
Relationship
Content
Interaction
Conversation
Messaging
Notification
Recommendation
Moderation
Search
Delivery
```

---

# 76. 推荐 Backend Domain

```text
/services
├── identity
├── profile
├── relationship
├── follow
├── block
├── mute
├── list
├── subscription
├── post
├── conversation
├── interaction
├── messaging
├── notification
├── recommendation
├── search
├── moderation
└── delivery
```

---

# 77. Relationship Service

负责：

```text
Follow
Unfollow
Follow request
Block
Unblock
Mute
Unmute
List membership
Subscription
```

输出：

```text
Relationship events
```

---

# 78. Content Service

负责：

```text
Post
Reply
Repost
Quote
Mention
Thread
Media
```

输出：

```text
Content events
```

---

# 79. Messaging Service

负责：

```text
Conversation
Conversation member
DM
DM request
Read receipt
Message edit
Message delete
Snooze
DM block
```

---

# 80. Notification Service

负责：

```text
Notification event
Preference
Filtering
Aggregation
Read/unread
Push
Email
Web notification
```

---

# 81. Notification 不应该直接由业务模块发送 Push

不要：

```text
LikeService
   ↓
sendPush()
```

应该：

```text
LikeService
   ↓
LIKE_EVENT
   ↓
Event Bus
   ↓
Notification Service
   ↓
Policy
   ↓
Push
```

优点：

```text
业务解耦
可重试
可聚合
可扩展
可审计
```

---

# 82. 最终对象关系图

```text
                           USER
                            │
       ┌────────────────────┼─────────────────────┐
       │                    │                     │
       ↓                    ↓                     ↓
 RELATIONSHIP            CONTENT              MESSAGE
       │                    │                     │
       ├─ Follow            ├─ Post              ├─ Conversation
       ├─ Follower          ├─ Reply             ├─ DM
       ├─ Block             ├─ Repost            ├─ Group DM
       ├─ Mute              ├─ Quote             ├─ Request
       ├─ List              ├─ Mention           ├─ Read
       └─ Subscribe         └─ Like              └─ Snooze
                                  │
                                  ↓
                              EVENT BUS
                                  │
                                  ↓
                           NOTIFICATION
                                  │
                    ┌─────────────┼──────────────┐
                    ↓             ↓              ↓
                  IN-APP         PUSH           EMAIL
```

---

# 83. 一句话总结 X 的系统逻辑

可以把 X 抽象成：

```text
Relationship
    ↓
determines
    ↓
who can see / interact / communicate
    ↓
Content & Message
    ↓
generate
    ↓
Events
    ↓
Policy + Filter + Preference
    ↓
Notifications / Delivery
```

或者更工程化地说：

```text
X = Social Graph
  + Content Graph
  + Conversation Graph
  + Event Graph
  + Notification Graph
  + Policy Engine
  + Recommendation Engine
```

其中：

```text
Social Graph
    解决「我和谁是什么关系」

Content Graph
    解决「谁发布了什么、谁与什么互动」

Conversation Graph
    解决「谁和谁私下通信」

Event Graph
    记录「系统发生了什么」

Notification Graph
    解决「谁需要被告知什么」

Policy Engine
    解决「什么情况下允许发生」

Recommendation Engine
    解决「什么内容应该展示给谁」
```

这套抽象比单纯复制 X 的页面结构更适合用于实际软件工程设计。


# 84. 产品设计时必须特别区分的 12 个概念

| 概念 | 本质 | 是否双向 | 是否公开 | 是否影响权限 |
|---|---|---:|---:|---:|
| Follow | 内容订阅 | 否 | 通常可见 | 是 |
| Follower | 被订阅 | 否 | 通常可见 | 是 |
| Mutual | 双向 Follow | 是 | 可推导 | 是 |
| Block | 强隔离 | 否 | 状态可被发现 | 强 |
| Mute | 内容过滤 | 否 | 对方不可知 | 弱 |
| List | 人工分类 | 否 | 可公开/私有 | 一般 |
| Subscribe | 付费关系 | 否 | 部分可见 | 部分 |
| Like | 内容互动 | 否 | 通常可见 | 一般 |
| Reply | 对话互动 | 否 | 通常公开 | 是 |
| Mention | 定向引用 | 否 | 通常公开 | 是 |
| DM | 私域通信 | 双方 | 私密 | 强 |
| Notification | 告知事件 | 不适用 | 私有 | 不直接 |

---

# 85. 最终建议：如果把这套逻辑用于自己的产品

优先实现：

## MVP

```text
User
Follow
Follower
Block
Mute

Post
Reply
Like
Repost
Mention

Conversation
DM
DM Request

Notification
Push
Read / Unread
```

## MVP2

```text
Group DM
List
Bookmark
Notification aggregation
Search
Message edit
Message delete
Snooze
```

## MVP3

```text
Subscription
Encrypted Chat
Calls
Recommendation
Advanced notification filtering
Moderation
Event Bus
```

## 高级阶段

```text
Graph recommendation
Social graph ranking
Notification ranking
Spam / abuse detection
Trust & safety
Multi-channel delivery
Real-time synchronization
End-to-end encryption
```

---

# 86. 参考资料

本文涉及 X 当前产品行为的主要依据：

- X Help Center — About Direct Messages
- X Help Center — About Chat
- X Help Center — About the Notifications timeline
- X Help Center — Following FAQs
- X Help Center — Blocking on X
- X Help Center — How to mute accounts
- X Help Center — About your For You timeline
- X Help Center — About conversations on X
- X Help Center — Help with Lists

说明：本文中的数据库表、Event Bus、Policy Engine、Notification aggregation 等属于**面向软件工程的架构抽象**，并非声称 X 内部真实数据库就是这种实现。
