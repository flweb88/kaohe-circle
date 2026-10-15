# 工单评估与分级规则（triage rules）

> 供 MainAgent 巡检时执行；`triage.sh` 给出字段校验/关键词初判，**最终优先级由 MainAgent 确认**。机器初判只做建议，不自动定 P0（P0 需 MainAgent 复核判定）。

## 1. 收件必填字段（缺失 → NEEDS-INFO）

| 字段 | 说明 | 缺失处理 |
|---|---|---|
| `title` | 一句话现象 | 必须补 |
| `reproduce` | 复现步骤（谁、在哪个页面、点了什么） | 必须补 |
| `expected` | 期望结果 | 必须补 |
| `actual` | 实际结果（附截图/报错文本） | 必须补 |
| `environment` | 环境：HTTP/HTTPS、设备（iPhone Safari/Windows Chrome/Mac）、账号、版本号 | 必须补 |
| `impact` | 影响范围（仅自己/多人/全部） | 可由 MainAgent 推断 |

缺任一必填字段 → 状态 `NEEDS-INFO`，自动生成追问清单回填，**不进入 READY**。

## 2. 分级判定（关键词初判 + MainAgent 确认）

### P0 候选（命中关键词且影响核心可用性）
- 现象：页面打不开/白屏/500、登录注册全挂、打卡链路全挂、数据丢失/串号、安全漏洞。
- 判定要点：**全局性、不可逆数据风险、核心链路不可用**。
- 处置：MainAgent 立即确认 → 进入 P0 抢占流程（README §6）。

### P1 候选（核心功能异常但有绕过 / 单用户数据错误）
- 现象：打卡无反应、结算不入账、同步失败、兑换/娱乐时间错误、补卡异常。
- 判定要点：**核心功能受损，但不是全挂，或影响单个用户**。
- 处置：插到维护队首（不抢占开发资源）。

### P2（非核心缺陷 / 可稳定绕过）
- UI 显示错误、按钮位置、重复显示、非关键流程报错。

### P3（轻微 / 建议）
- 错别字、对齐、优化建议、边角 case。

## 3. 分级确认后的动作

1. 写工单 frontmatter：`priority`、`module`、`status: TRIAGED`、`due_response_at`、`due_fix_at`（由 config 中 SLA 时限 + 当前时间算出）。
2. 判断是否重复：与未关闭工单比对 `title + 症状`，疑似重复挂 `duplicate-of: M-xxxx`，关闭新单并在原单追加。
3. 信息齐全 → 转 `READY`；维护队列当前 WIP < 2 且无文件锁冲突 → 直接 `ASSIGNED` 并 create_agent。
4. 维护/开发归属存疑：默认进 MAINT；确认是新增能力 → 转 FEAT，在工单写 `transferred-to: FEAT`，不在维护看板保留。

## 4. 模块归属（module_map 摘要，完整版见 workflow.config.json）

| 现象关键词 | 归属文件 |
|---|---|
| 打卡/结算/补卡/页面/UI/白屏/跳转 | `kaohe.html` |
| 登录/注册/密码/token | `kaohe-api/auth.php` |
| 保存/推送/同步/数据覆盖 | `kaohe-api/save.php` |
| 拉取/加载/云端不显示 | `kaohe-api/load.php` |
| 动态/评论/点赞/转发/关注 | `kaohe-api/social.php` |
| 私信/消息/通知 | `kaohe-api/dms.php` |
| 删除账号/注销/管理员 | `kaohe-api/account_cleanup.php` |
| 邀请码/后台配置 | `kaohe-api/admin.php` |
| 头像/昵称/主页 | `kaohe-api/profile.php` |
| 图片/上传/视频/压缩 | `kaohe-api/media.php` |

## 5. 文件锁（防并发冲突）

`kaohe.html` 是单文件前端，同一时刻只允许一个工单改动。看板上 `FIXING` 中单若持有 `锁: kaohe.html`，后续同模块工单即使 WIP 有名额也保持 `READY`，直到锁释放。

## 6. 不允许自动做的事（边界）

- 不自动定 P0 并抢占（必须 MainAgent 复核）。
- 不自动删除生产数据；数据修复必须先备份。
- 不自动改管理员账号/权限相关生产数据。
- 返工到第 3 次必须停，转 `NEEDS-HUMAN`，不无限重试。
