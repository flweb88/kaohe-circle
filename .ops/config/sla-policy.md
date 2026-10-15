# SLA 策略与升级规则

> 时区 Asia/Shanghai；工作时段 09:00–22:00。SLA 由 `sla-check.sh` 每天 09:03 / 14:03 / 20:03 巡检计算，MainAgent 据此升级/告警。

## 1. SLA 时限（工作时段口径）

| 优先级 | 响应 SLA（INBOX→ASSIGNED） | 修复 SLA（TRIAGED→DEPLOYING） | 超时升级 |
|---|---|---|---|
| P0 | 15 分钟 | 2 小时 | 超时即升级（直接提醒 owner，且不恢复被抢占资源） |
| P1 | 1 小时 | 8 小时（工作时段） | 超时升 P0 提醒 |
| P2 | 4 小时 | 2 个工作日 | 超时升 P1 |
| P3 | 1 个工作日 | 随下一个版本 | 不强制，仅在队列报告体现 |

## 2. 计时规则

- 起算点：`created_at`（进 INBOX）。
- 响应计时：到 `assigned_at` 结束。
- 修复计时：到 `deployed_at`（进 DEPLOYING）结束。
- `BLOCKED` 状态暂停计时（等待外部信息/权限/网络），记录 `paused_at` / `resumed_at`，SLA 截止时间顺延。
- `NEEDS-INFO` 等待用户补充期间同样暂停。
- 非工作时段（22:00–次日 09:00）的 P2/P3 不计时；P0/P1 全天计时。

## 3. 巡检输出（sla-check.sh 产物）

每次巡检在看板顶部「SLA 告警区」输出：

| 标记 | 含义 |
|---|---|
| 🔴 已超时 | 超过 fix SLA，未部署 |
| 🟠 临期 | 剩余 < 25% SLA |
| 🟢 正常 | 时限内 |
| ⏸️ 暂停 | BLOCKED / NEEDS-INFO |

同时输出一份机器可读清单到 `.ops/evidence/sla-{日期}.json`（供 dashboard 渲染）。

## 4. 自动升级动作

1. P1 超时 → 升级为 P0 级别处置（插入队首、提示是否需要抢占），但**不自动抢占**，由 MainAgent 判定。
2. P2 超时 → 升 P1，重排到维护队首。
3. 返工达到 2 次仍失败 → 直接 `NEEDS-HUMAN`（与 SLA 无关，是质量熔断）。
4. 所有 🔴 工单在巡检 query 中被点名，MainAgent 必须在本轮处理或说明阻塞原因。

## 5. 告警渠道（当前与后续）

- **当前可用**：看板标红 + cron 唤醒 MainAgent 后在会话中提示；`sla-check.sh` 输出告警清单。
- **后续可接（尚未配置，不假设已具备）**：项目 SMTP 已在 `kaohe-api/admin.php` 配置，可加发邮件；或企业微信/飞书 webhook（`chat_webhook` 字段已存在）。接入后另开工单。

## 6. P0 特殊计时

- P0 不区分工作时段，全天 24 小时计时。
- 响应 SLA 15 分钟：若 cron 未到点，owner 应直接在会话触发（不等巡检）。
- P0 从 INBOX 到 DONE 全程在 `.ops/audit/` 留时间线，事后复盘是否满足 2 小时修复 SLA。
