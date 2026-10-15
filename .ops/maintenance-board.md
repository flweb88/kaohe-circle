# 维护看板（Maintenance Board）

> **唯一事实源**：本文件是维护队列的运行态看板。MainAgent 每次被唤醒（cron 巡检或人工触发）先读本文件，再按 `.ops/README.md` 总纲推进；任何状态变更必须立即回写本文件 + 对应工单档案。
> 更新时间：2026-10-16 00:05（M-0002 已 DONE 上线 v3.9.1）　|　维护队列 WIP：`0/2`（P0 时可临时到 3）　|　开发队列 WIP：`0/1`　|　文件锁：无

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

| ID | 标题 | 级别 | 模块 | 状态 | 负责人/Agent | SLA | 文件锁 |
|---|---|---|---|---|---|---|---|
| （当前无在途工单） | | | | | | | |

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
| M-0001 | 公开仓库默认邀请码与内网 NAS 地址清理（注册纵深防御 + worker 脱敏） | P2 | 2026-10-15 15:00 | 后端（前端仍 v3.8.9） | `.ops/evidence/M-0001/`（本地，不入库） |
| M-0002 | 未来打卡任务提前可见/可打卡且首个周期错误结算（未开始任务「显示但锁定」+ 按起始日结算） | P2 | 2026-10-16 00:05 | v3.9.1（commit `0030c3c`） | `_evidence/v391-future-lock/`（本地，不入库；工单 `.ops/tickets/M-0002.md`） |

> 历史已修复版本（v3.8.1–v3.8.9）在维护流上线前完成，见总览 §3.1 版本表，不在本看板追溯。
>
> M-0001 复盘：维护看板首单完整走通"工单自动评估 → P2 分级 → 子 Agent 执行（修复+回归+部署）→ MainAgent 独立复核 → 上线归档"。仅部署 common.php/auth.php；worker.sh/plist 仓库脱敏不部署 web；数据零改动（users=4/admin=1/邀请码不变）；回滚备份在 NAS 个人目录 `~/.m0001-deploy-backup-*`。真实邀请码与内网地址不入库。

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
- 2026-10-15：M-0001（P2 安全加固）全流程闭环并归档 DONE：子 Agent 执行 + MainAgent 独立复核；仅部署 common.php/auth.php，数据零改动；worker.sh/plist 仓库脱敏随代码提交；回滚备份留存 NAS 个人目录。
- 2026-10-15 23:34：**补录对话直提漏单**——M-0002（未来打卡任务 bug，v3.9.1）对话直提被直接委派、未先进看板，现补建 tickets/M-0002.md 并置 FIXING（维护 WIP 1/2，kaohe.html 加锁）。复盘：看板只接 inbox 自动入口，"对话直提"无强制建单；且 FEAT 队列无 tickets 明细（dashboard 仅显示开发 WIP 计数）。机制加固待办：①对话直提必须先建单回写 board 再 create_agent；②FEAT 落地 tickets/F-* 并让 render-dashboard 支持明细；③收口强制 render。
- 2026-10-16 00:05：M-0002（P2 未来打卡任务 bug）全流程闭环并归档 DONE、上线 v3.9.1：实现子 Agent 与独立复核子 Agent 分离（node --check、引擎/门禁 44+28 断言、渲染冒烟 28 项全过），MainAgent 独立终审（git diff 仅 kaohe.html、本地/NAS/公网三方 sha 一致 `25fa09d3`、版本串两处 v3.9.1）；维护 WIP 回 0/2、kaohe.html 锁释放；代码 commit `0030c3c`、总览 §3.1 登记。返工 0 次；遗留真机 Safari 锁定卡 GUI 由用户登录确认（非阻断）。同步立"对话直提必须先建单"铁律（README §2.1.1）；FEAT 看板明细化（F- 泳道）另派子 Agent 实施中。
