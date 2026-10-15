# 维护看板（Maintenance Board）

> **唯一事实源**：本文件是维护队列的运行态看板。MainAgent 每次被唤醒（cron 巡检或人工触发）先读本文件，再按 `.ops/README.md` 总纲推进；任何状态变更必须立即回写本文件 + 对应工单档案。
> 更新时间：2026-10-15 初始化　|　维护队列 WIP：`0/2`（P0 时可临时到 3）　|　开发队列 WIP：`0/1`

---

## 0. MainAgent 唤醒检查清单（每次必做）

1. 扫 `.ops/inbox/`：新单 → 按 `config/triage-rules.md` 评估；缺字段 → NEEDS-INFO；齐全 → TRIAGED/READY。
2. 扫本看板「在途工单」：READY 且 WIP 有空位、无文件锁冲突 → create_agent 分派。
3. 跑 `.ops/scripts/sla-check.sh`（已建后）：更新 §1 SLA 告警区，🔴/🟠 按 `config/sla-policy.md` 升级。
4. 看 REGRESSING / VERIFYING：推进或判定返工；返工达 2 次 → NEEDS-HUMAN。
5. 看 P0：命中抢占规则 → 按 README §6 执行并写 `.ops/audit/`。
6. 状态变更后：回写本看板 + 工单档案；刷新 dashboard.html；收口时更新总览 §3.1 并 push GitHub。

---

## 1. SLA 告警区

| 标记 | 工单 | 优先级 | 状态 | 剩余/超时 | 动作 |
|---|---|---|---|---|---|
| — | （暂无） | | | | |

> 🔴 已超时　🟠 临期(<25%)　🟢 正常　⏸️ 暂停(BLOCKED/NEEDS-INFO)

---

## 2. 队列与 WIP 概览

### 维护队列（MAINT，前缀 M-）
| 状态 | 数量 | 工单 |
|---|---|---|
| INBOX 收件 | 0 | |
| NEEDS-INFO 待补信息 | 0 | |
| READY 待分派 | 0 | |
| ASSIGNED/FIXING 修复中 | 0 | |
| REGRESSING 回归中 | 0 | |
| REWORK 返工 | 0 | |
| DEPLOYING/VERIFYING 部署验证 | 0 | |
| BLOCKED 阻塞 | 0 | |
| NEEDS-HUMAN 需人工 | 0 | |
| **当前 WIP** | **0/2** | 文件锁：无 |

### 开发队列（FEAT，与维护隔离，共享子 Agent 资源池）
| 状态 | 数量 | 说明 |
|---|---|---|
| 修复/开发中 | 0 | 详见各 MVP 文档与总览 §3.1；常态 WIP 0/1，P0 时可被抢占到 0 |

---

## 3. 在途工单（按优先级排序）

> 字段：ID ｜ 标题 ｜ 级别 ｜ 模块 ｜ 状态 ｜ 负责人/Agent ｜ SLA ｜ 文件锁

（当前无在途工单）

---

## 4. P0 抢占记录

| 抢占 ID | 时间 | P0 工单 | 被抢占单 | 资源变化 | 恢复状态 | 审计文件 |
|---|---|---|---|---|---|---|
| （暂无） | | | | | | |

---

## 5. 待分派（READY Backlog）

| 排序 | ID | 标题 | 级别 | 模块 | 等待原因 |
|---|---|---|---|---|---|
| （暂无） | | | | | |

---

## 6. 最近关闭归档（DONE，近 10 条）

| ID | 标题 | 级别 | 关闭时间 | 版本 | 复核包 |
|---|---|---|---|---|---|
| （维护流上线后从首单开始记录） | | | | | |

> 历史已修复版本（v3.8.1–v3.8.9）在维护流上线前完成，见总览 §3.1 版本表，不在本看板追溯。

---

## 7. 状态机与规则速查

- 状态机、返工上限（2 次）、P0 抢占、DoD 门禁：见 `.ops/README.md` §3/§6/§7。
- 分级与模块归属：`.ops/config/triage-rules.md`。
- SLA 时限与升级：`.ops/config/sla-policy.md`。
- 机器可读配置：`.ops/config/workflow.config.json`。
- 工单模板：`.ops/templates/bug-ticket.md`；回归报告：`.ops/templates/regression-report.md`。

---

## 8. 变更日志（看板自身）

- 2026-10-15：维护看板初始化，双队列隔离、WIP、SLA、P0 抢占规则落地。
