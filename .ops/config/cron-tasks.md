# 定时任务说明（cron jobs）

> 用途：供 MainAgent 用 `create_cron_job` 录入「维护巡检」「SLA 检查」两个定时任务。
> **重要前提**：cron 只负责「到点唤醒 MainAgent」，**不是常驻守护进程**。Agent 不运行时没有任何进程在自主修复；自动化粒度 = 巡检间隔（主巡检每天一次 09:13；SLA 独立复核每 3 天一次 09:23）。P0 工单要求「立即」时，用户/监控可直接在会话里发一句即时触发，**不必等 cron**。
> 录入备注：两任务均 `enabled=true`；时区 Asia/Shanghai（UTC+8）。
> 节奏取自 `.ops/config/workflow.config.json` §cron，**已与系统实际录入的任务核对一致（2026-10-15 应要求降频）**：主巡检 `13 9 * * *`（每天 09:13）；SLA 检查为 rrule `DTSTART:20261016T092300` + `RRULE:FREQ=DAILY;INTERVAL=3`（每 3 天 09:23，首次 2026-10-16）。

---

## 任务一：维护巡检（triage）

- **title**：维护巡检（triage）
- **schedule_type**：`cron`
- **schedule**：`13 9 * * *`
- **节奏说明**：**每天 09:13 一次（:13 触发）**，与 config `cron.triage` 及系统实际录入任务一致；:13 避开整点系统/备份高峰。家庭项目每天例行巡检一次即可、省额度（2026-10-15 由每小时一次降为每天一次）；P0 不依赖例行巡检（用户在会话即时触发）。

**query（唤醒后执行的完整指令，可直接粘贴）：**

```
本次请求是由「维护巡检（triage）」定时任务到时触发的。

请按 .ops/README.md 工作流执行一轮维护巡检，全程只读看板与工单档案，不碰生产数据、不擅自部署。步骤：

1. 先读 .ops/maintenance-board.md（唯一事实源）与 .ops/README.md（总纲），以及 .ops/config/triage-rules.md、.ops/config/sla-policy.md、.ops/config/workflow.config.json，确认当前 WIP、文件锁与规则口径。

2. 在项目根运行 bash .ops/scripts/triage.sh，扫描 .ops/inbox/ 新单并逐单处理：
   - 缺必填字段（复现步骤/期望 vs 实际/环境）→ 标 NEEDS-INFO，生成追问清单，不进入 READY；
   - 信息齐全 → 按 triage-rules.md 由 MainAgent 确认优先级与模块（机器只给初判）；命中 P0 关键词必须由 MainAgent 复核，不得自动定 P0；
   - 命中 P0 且需立即动用正在跑的子 Agent 资源时，按 README §6 执行抢占：先 peek_task_progress 冻结现场 → 资源让渡（维护 WIP 可临时到 3，必要时暂停开发队列到 0）→ 拉起 P0，并向 .ops/audit/preempt-{时间戳}.md 追加一条审计（schema 见 README §6.3），无记录不得抢占；
   - 判重：与未关闭工单比对，疑似重复挂 duplicate-of 不新开；
   - READY 且维护 WIP 有空位（<2，P0 时 <3）且与 kaohe.html 文件锁无冲突 → create_agent 分派，并回写工单档案与看板状态。

3. 推进在途工单：扫 REGRESSING / VERIFYING / REWORK 状态单——回归/线上验证通过则推进到下一状态，失败则回 REWORK 并记录返工次数；同一单自动返工达 2 次仍失败 → 标 NEEDS-HUMAN，停止自动重试（质量熔断）。BLOCKED / NEEDS-INFO 单核对暂停原因与外部依赖进展。

4. 任何状态变更后：立即回写 .ops/maintenance-board.md 与对应 .ops/tickets/M-*.md 工单档案，然后运行 bash .ops/scripts/render-dashboard.sh 刷新 .ops/dashboard.html 的数据。

5. 本轮处理完成后，在会话中简报：新收几单、分派了几单、推进/返工/熔断各几单、有无 P0 抢占、有无 SLA 风险、遗留阻塞。
```

---

## 任务二：SLA 检查

- **title**：SLA 检查
- **schedule_type**：`rrule`
- **schedule**（两行，本地时区、不带 TZID/Z）：
  ```
  DTSTART:20261016T092300
  RRULE:FREQ=DAILY;INTERVAL=3
  ```
- **节奏说明**：**每 3 天 09:23 一次**（严格每 3 天、跨月不重置；首次 2026-10-16，之后 10-19 / 10-22 / 10-25…），紧接主巡检 09:13 之后。独立 SLA 复核为低频（2026-10-15 由每天 3 次降为每 3 天一次）；主巡检每天唤醒时也会跑 `sla-check.sh`，P0 由用户在会话即时触发。

**query（唤醒后执行的完整指令，可直接粘贴）：**

```
本次请求是由「SLA 检查」定时任务到时触发的。

请按 .ops/config/sla-policy.md 执行一轮 SLA 巡检，全程只读看板与工单档案，不碰生产数据、不擅自部署、不自动抢占开发资源。步骤：

1. 在项目根运行 bash .ops/scripts/sla-check.sh（如日期口径有需要可带当天 --date YYYY-MM-DD）。脚本扫所有在途工单的响应/修复 SLA，输出 §1 SLA 告警清单（🔴 已超时 / 🟠 临期<25% / 🟢 正常 / ⏸️ 暂停）。

2. 用脚本输出的 §1 片段替换 .ops/maintenance-board.md 的「§1 SLA 告警区」表格，保持列结构（标记/工单/优先级/状态/剩余超时/动作）。

3. 按 sla-policy.md §4 处置告警：
   - 🔴 已超时工单在本轮 query 中逐个点名，MainAgent 必须处理，或明确说明阻塞原因；
   - P1 超时 → 建议升 P0（插入维护队首，提示是否需要抢占开发资源），但不自动抢占，由 MainAgent 判定；
   - P2 超时 → 建议升 P1，重排到维护队首；
   - P0 超时 → 直接提醒 owner（P0 全天 24h 计时，不恢复被抢占资源）；
   - ⏸️ BLOCKED / NEEDS-INFO 单核对暂停原因是否仍然成立，记录 paused_at/resumed_at，SLA 截止顺延。
   升级/建议在看板或工单档案留痕（escalations 记录 from/to/reason）。

4. 回写 .ops/maintenance-board.md 与相关工单档案后，运行 bash .ops/scripts/render-dashboard.sh 刷新 .ops/dashboard.html。

5. 在会话输出本轮告警简报：🔴 几条（各是什么、处置结论）、🟠 几条、⏸️ 几条、建议升级几条、无需处理的正常单数量。
```

---

## 录入检查清单（MainAgent 核对用）

- [x] 主巡检 `schedule_type=cron`，SLA 检查 `schedule_type=rrule`；
- [x] 主巡检 schedule `13 9 * * *`（= config `cron.triage`，每天 09:13，已录入并在运行；2026-10-15 由每小时一次降为每天一次）；
- [x] SLA schedule `DTSTART:20261016T092300` + `RRULE:FREQ=DAILY;INTERVAL=3`（= config，每 3 天 09:23、首次 2026-10-16，已录入并在运行）；
- [x] 两 query 首句均为「本次请求是由「{title}」定时任务到时触发的」，title 与任务名一字不差；
- [x] 两任务 `enabled=true`；时区 Asia/Shanghai。
