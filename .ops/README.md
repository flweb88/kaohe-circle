# 考核圈 · 软件更新维护 AI Agent 看板工作流（Ops Workflow）

> 文档定位：维护（maintenance/bugfix）工作流的**运行总纲与操作手册**。
> 事实基线：2026-10-15 盘点，基于本机真实可用的 Agent 系统、Skill、脚本与部署链路编写，未配置的能力一律标注「待建」，不假设其存在。
> 维护原则：维护看板与新功能开发看板**队列隔离、独立 WIP、共享子 Agent 资源池**；P0 工单可带审计日志临时抢占。

---

## 1. 系统架构与能力边界（先读，避免误判）

### 1.1 真实运行模型

本系统**不是常驻调度服务**，而是「定时唤醒 + 会话内执行」模型：

```
cron 定时任务（唤醒器，系统级常驻）
   │  到点触发一条 query，唤醒 MainAgent
   ▼
MainAgent（调度器 / 复核者，会话内运行）
   │  读 Markdown 看板（唯一事实源）→ 跑脚本 triage → 评估分级
   │  → create_agent 分派子 Agent → 复核 → 部署 → 更新看板
   ▼
OrganizerAgent / 子 Agent（执行器，会话内运行）
   │  修复代码 / 跑回归 / 部署 / 写证据
   ▼
shell 脚本（机械工具，agent 用 Bash 调用，或 cron 直接跑）
   │  lint / 回归 / SLA 计时 / 看板渲染
   ▼
Markdown 看板 + JSON 状态 + 审计日志（持久化，agent 非运行时也在）
```

**关键推论**：
- Agent 不运行时，**没有任何进程在自主修复**；自动化的粒度 = cron 唤醒间隔（维护巡检**每天 09:13 一次**；独立 SLA 复核每 3 天 09:23 一次；P0 不依赖例行巡检，由用户在会话即时触发）。
- 「自动评估、自动分派、自动返工」= cron 唤醒 MainAgent 后，由 MainAgent 按本总纲执行；不是后台守护进程实时响应。
- P0 工单要求「立即」时，**用户/监控直接在会话里发一句**即可立即触发，不必等 cron。
- 看板状态、工单、审计日志全部落盘为 Markdown/JSON，保证 agent 会话结束后状态不丢。

### 1.2 四类资产盘点（现状 vs 待建）

| 类别 | 资产 | 状态 | 说明 |
|---|---|---|---|
| **已有可复用** | `create_agent`（OrganizerAgent） | ✅ 现成 | 分派子 Agent 执行修复/部署 |
| | `task_create/update/list/get` | ✅ 现成 | 会话内任务看板（轻量 WIP 跟踪） |
| | `peek_task_progress` / `kill_agent` | ✅ 现成 | 进度查看 / 取消失控子 Agent |
| | `create_cron_job` 系列（cron/at/rrule） | ✅ 现成 | 定时唤醒巡检、SLA 检查 |
| | `deploy.sh` | ✅ 现成 | php -l → 推送 → 头像 → git 提交 → URL 验证，已含 lint 门禁 |
| | Git 双库（GitHub `kaohe-circle` + NAS web 库） | ✅ 现成 | 版本与回滚锚点 |
| | 复核包机制（`复核包-v3.8.x.md`） | ✅ 现成 | 根因/diff/测试/生产验证的复核模板 |
| | staging 测试账号（隔离账号，仅本地配置） | ✅ 现成 | 隔离回归，不碰生产数据 |
| | SSH 部署链路（`用户@NAS主机`，见本地配置） | ✅ 现成 | 密钥免密，`cat >` 管道推送 |
| **需要配置的 Skill** | `doubao-cron-scheduler` | ✅ 已配置 | 「维护巡检」「SLA 检查」两个 cron 已录入并在运行（见 §5） |
| | `verifier-hub` | 🔧 已装（可选） | 交付前文件/产物校验；当前回归门禁以 php -l + staging 场景为主，verifier 可按需补 |
| | `artifact-preview` | ✅ 已用 | 产物渲染核对；本机无 Chromium，HTML 看板改用 Safari 打开 `file://` 截图验证（见 §8.1） |
| **需要新增的项目配置** | `.ops/` 目录与看板 | ✅ 已落地 | 见 §8 |
| | 工单 schema、看板配置、SLA/抢占规则 | ✅ 已落地 | 机器可读 JSON + 人类可读 MD；真值放 `workflow.config.local.json`（不入库） |
| | 工单模板、回归报告模板 | ✅ 已落地 | 标准化产出 |
| **需要编写的程序模块** | `scripts/triage.sh` | ✅ 已建成（子 Agent，2026-10-15） | 扫 inbox → 校验字段 → 算 SLA 时限 → 看板落位（只读，不自动定 P0） |
| | `scripts/regression.sh` | ✅ 已建成（子 Agent） | php -l + 关键接口冒烟 + 版本号核验，出报告；本轮未做全流程演练 |
| | `scripts/sla-check.sh` | ✅ 已建成（子 Agent） | 扫在途工单 SLA，超时标红、输出告警清单（只建议升级，不自动升级） |
| | `render-dashboard.sh` + `dashboard.html` | ✅ 已建成（子 Agent） | 读看板 JSON 渲染可视化看板（双泳道 + WIP + SLA + 归档） |

> 明确**不存在、不假设**的能力：常驻后台守护进程、工单实时推送通道、自动监控用户报错的探针（目前无前端错误上报后端）。这些在 §9 列为后续演进项，不在本次自动化承诺内。

---

## 2. 双队列隔离与资源池

### 2.1 两条独立队列

| 队列 | 看板文件 | 目录 | 用途 | 编号前缀 |
|---|---|---|---|---|
| **维护队列（MAINT）** | `.ops/maintenance-board.md` | `.ops/tickets/M-*` | 线上缺陷、回归、热修、数据修复、运维故障 | `M-` |
| **开发队列（FEAT）** | 各 `考核圈-MVPx-*.md` + 总览 §3.1 版本表 | MVP 文档 | 新功能、增强、技术债 | `F-`（沿用版本号） |

- 两条队列**各自独立 WIP 上限**，互不挤占排队名额。
- 一个问题到底走哪条：**「已上线行为与预期不符 / 数据错误 / 服务不可用」→ MAINT；「线上没有、要新增」→ FEAT。** 拿不准默认进 MAINT inbox，由 triage 判定后可转 FEAT（留下转移记录）。

### 2.2 共享子 Agent 资源池与 WIP

| 队列 | 并发 WIP（修复中子 Agent 数） | 排队上限 | 说明 |
|---|---|---|---|
| 维护队列（常态） | 2 | 8 | 大部分时候 1 个热修 + 1 个低优 |
| 开发队列（常态） | 1 | 6 | 新功能串行，避免单文件 `kaohe.html` 冲突 |
| **P0 抢占时** | 维护临时 +1（可到 3），开发可被暂停到 0 | 不变 | 见 §6 |

> 单文件前端 `kaohe.html` + 21 个 PHP，**并发改动极易冲突**，所以常态总并发控制在 3 以内，且同文件工单必须串行（看板上用 `锁: kaohe.html` 标记）。

---

## 3. 工单生命周期（维护队列状态机）

```
                  ┌─────────────────────────────────────────┐
                  ▼                                         │
[INBOX 收件] → [TRIAGED 已评估] → [READY 待分派] → [ASSIGNED 已分派]
                                                          │
                                                          ▼
                                              [FIXING 修复中] ◀── 返工
                                                          │        ▲
                                            ┌─────────────┘        │
                                            ▼                      │
                                     [REGRESSING 回归中]            │
                                            │                       │
                              ┌─────────────┴──────────┐            │
                          失败▼                     通过▼            │
                    [REWORK 返工]──(回 FIXING)   [DEPLOYING 部署中]  │
                                                     │              │
                                                     ▼              │
                                            [VERIFYING 线上验证]    │
                                                     │              │
                                       ┌─────────────┴────────┐     │
                                   失败▼                  成功▼    │
                                  [REWORK]            [DONE 关闭]──┘
```

| 状态 | 含义 | 谁推进 |
|---|---|---|
| `INBOX` | 新报障，未评估 | 报修人/巡检投递 |
| `TRIAGED` | 已判优先级、模块、SLA，信息齐全 | MainAgent triage |
| `READY` | 等待分派（WIP 有空位） | triage 脚本 |
| `ASSIGNED` | 已 create_agent，子 Agent 未产出 | MainAgent |
| `FIXING` | 子 Agent 修复中 | 子 Agent |
| `REGRESSING` | 跑回归脚本 | 子 Agent / MainAgent |
| `REWORK` | 回归失败，返工（记录返工次数） | MainAgent 判定 |
| `DEPLOYING` | 部署 NAS（deploy.sh） | 子 Agent（已授权复核即上线） |
| `VERIFYING` | 线上冒烟验证 | MainAgent 复核 |
| `DONE` | 关闭，归档复核包 | MainAgent |
| `BLOCKED` | 依赖外部（用户信息/网络/权限），计时暂停 | MainAgent |

**返工上限**：同一工单自动返工 ≤ 2 次；第 3 次仍失败 → 自动升级为人工介入（标记 `NEEDS-HUMAN`，停止自动重试，不无限循环）。

---

## 4. 自动评估与优先级分级（triage）

### 4.1 优先级定义与 SLA

| 级别 | 判定（满足任一） | 响应 SLA | 修复 SLA | 是否可抢占 |
|---|---|---|---|---|
| **P0 致命** | 线上完全不可用；数据丢失/串号；登录/注册/打卡核心链路全挂；安全漏洞 | 15 分钟（全天） | 2 小时（全天） | ✅ 可抢占，立即唤醒 |
| **P1 严重** | 核心功能（打卡/结算/同步/支付兑换）异常但有绕过；单用户数据错误 | 1 小时（全天） | 8 小时（全天） | ❌ 排队但插队到维护队首 |
| **P2 一般** | 非核心功能缺陷；UI 显示错误；可稳定绕过 | 4 小时（每日 09–22） | 2 个工作时段日（每日 09–22，含周末） | ❌ 正常排队 |
| **P3 轻微** | 文案、样式、边角 case、优化建议 | 1 个工作时段日（每日 09–22，含周末） | 下个版本随版 | ❌ 填闲处理 |

- **SLA 计时**：`响应` = 从 INBOX 到 ASSIGNED；`修复` = 从 TRIAGED 到 DEPLOYING。
- `BLOCKED` 状态 SLA 计时暂停（依赖外部，不算团队超时）；`NEEDS-INFO` 同样暂停。
- SLA 时限由 `triage.sh` 按优先级 + 当前时间算出 `due_at`，写入工单 frontmatter。
- **计时窗口口径（与脚本实现一致，2026-10-15 端到端实测）**：P0/P1 全天 24h 计时（跨夜连续、不暂停）；P2/P3 仅在每日 09:00–22:00 窗口内顺延，**周末不暂停**（家庭打卡每天都在运行，周末也须响应），仅夜间 22:00–次日 09:00 暂停。详见 `config/sla-policy.md`。

### 4.2 自动 triage 规则（MainAgent 执行，脚本辅助）

1. **收件校验**：缺「复现步骤 / 期望 vs 实际 / 环境（HTTP/HTTPS、设备、账号）」→ 自动标 `NEEDS-INFO`，不进入 READY，生成追问清单。
2. **关键词分级建议**（脚本给初判，MainAgent 确认）：
   - 命中「打不开 / 数据丢了 / 串号 / 登不上 / 全是别人的数据 / 安全」→ P0 候选
   - 命中「打卡没反应 / 结算没到账 / 同步失败 / 兑换错误」→ P1 候选
   - 其余 → P2/P3
3. **模块归属**：关键词映射到 `kaohe.html` 前端 / `kaohe-api/*.php` 具体接口（映射表见 `config/triage-rules.md`）。
4. **重复合并**：与看板上未关闭工单做标题/现象相似度比对，疑似重复 → 挂 `duplicate-of`，不新开。
5. **信息齐全 + 分级确认** → 落 READY，写 `due_at`。

---

## 5. 自动化闭环（cron 唤醒节奏）

| 定时任务 | 频率 | 唤醒后 MainAgent 做什么 | 对应脚本 |
|---|---|---|---|
| **维护巡检** | **每天 09:13 一次（:13 触发）** | 扫 inbox 做 triage；READY 且 WIP 有空位则分派；VERIFYING 单做线上验证；推进卡住的单 | `triage.sh` |
| **SLA 检查** | 每 3 天 09:23 一次（rrule，严格每 3 天） | 扫在途工单 SLA，临期/超时标红，输出告警清单，**建议**升级（不自动升级，由 MainAgent 判定）；主巡检每天也会跑 `sla-check.sh` | `sla-check.sh` |
| **回归门禁** | 部署前由子 Agent 触发（非 cron） | 修复后必跑，失败自动返工 | `regression.sh` |
| **看板渲染** | 每次状态变更后触发 | 刷新 dashboard.html 数据 | `render-dashboard.sh` |

> cron 只负责「唤醒 + 执行 query」。query 内写死：读哪个看板、跑哪个脚本、按什么规则分派/升级/告警。两个任务均已录入系统并在运行（2026-10-15），完整 query 文本见 `config/cron-tasks.md`，频率以该文件与 `workflow.config.json` §cron 为准（已与系统实际任务核对一致）。

---

## 6. P0 紧急抢占规则（带审计）

### 6.1 触发条件
仅 §4.1 表中 P0 判定成立，且需要立即动用正在跑的子 Agent 资源时。

### 6.2 抢占动作（MainAgent 执行，全程留痕）
1. **冻结现场**：`peek_task_progress` 记录被抢占子 Agent 当前进度；让其在当前安全点停（能用 `kill_agent` 则挂起，不能杀则等其完成当前最小步）。
2. **资源让渡**：维护队列 WIP 临时 +1；如仍不足，暂停一个 FEAT 队列子 Agent（开发 WIP 可降到 0）。
3. **拉起 P0**：立即 create_agent 处理 P0，hint 里给全线上地址、SSH、数据文件路径、回滚锚点。
4. **审计落盘**：抢占瞬间向 `.ops/audit/preempt-{时间戳}.md` 追加一条（见 §6.3）。
5. **恢复**：P0 关闭（DONE）后，按审计记录恢复被暂停的工单/子 Agent，回到原 WIP。

### 6.3 审计日志 schema（`.ops/audit/preempt-*.md`）
```yaml
preempt_id: PR-20261015-001
at: 2026-10-15T15:22:00+08:00
p0_ticket: M-0014
reason: 线上打卡全链路 500
preempted_from:
  - ticket: F-0207（MVP8 优化）
    agent_id: o_xxx
    progress_snapshot: "已完成 3/5 子任务，kaohe.html 未改"
    action: paused
resource_change: "维护 WIP 2→3；开发 WIP 1→0"
approved_by: auto-rule-P0（owner 已授权 P0 自动抢占）
resolved_at: 2026-10-15T16:40:00+08:00
restore_action: "恢复 F-0207，开发 WIP 回到 1"
```

> P0 抢占是**已授权动作**（owner 已明确「复核通过即可上线、紧急工单可先行」），但每次抢占必须写审计，事后 owner 可追溯，不允许无记录抢占。

---

## 7. 回归、部署与验证门禁（DoD）

维护工单进入 `DONE` 必须全部满足：

1. **静态门禁**：`php -l` 全部 PHP 通过；前端无明显语法错误（node 检查或加载验证）。
2. **回归脚本**：`regression.sh` 全绿（关键接口冒烟：登录/load/save/打卡/结算；版本号两处一致）。
3. **隔离验证**：staging 测试账号复现「修复前失败 → 修复后通过」，证据存 `.ops/evidence/{ticket}/`。
4. **数据安全**：涉及数据修复的，先在 NAS 备份原文件（`.bak-{时间戳}`），不动其他用户数据。
5. **复核包**：产出 `复核包-{ticket}.md`（根因 / diff / 测试 / 线上验证 / 回滚锚点）。
6. **线上验证**：部署后 `curl` 核验版本号 + HTTP 状态 + 关键页面。
7. **看板归档**：状态 → DONE，更新总览 §3.1 版本表，GitHub commit/push。

任何一条不满足 → 回 `REWORK`（≤2 次，否则 `NEEDS-HUMAN`）。

---

## 8. 目录结构与本次落地清单

```
.ops/
├── README.md                       # 本总纲（工作流设计 + 实测记录）
├── maintenance-board.md            # 【运行态】维护看板（双泳道 + 状态 + WIP + SLA，唯一事实源）
├── config/
│   ├── workflow.config.json        # 机器可读（入库，脱敏占位）：WIP、SLA、路径、模块映射、cron
│   ├── workflow.config.local.json  # 【不入库】真值：NAS SSH、线上 URL、测试账号（从 .local.example.json 复制）
│   ├── triage-rules.md             # 工单评估/分级/模块归属规则
│   ├── sla-policy.md               # SLA 策略与升级规则
│   └── cron-tasks.md               # 两个 cron 任务的 schedule 与完整 query
├── inbox/                          # 新工单投递区（运行态，仅 .gitkeep 入库）
├── tickets/                        # 工单档案（运行态，M-xxxx.md，仅 .gitkeep 入库）
├── audit/                          # P0 抢占审计日志（运行态，仅 .gitkeep 入库）
├── templates/
│   ├── bug-ticket.md               # 工单模板
│   └── regression-report.md        # 回归报告模板
├── scripts/                        # triage.sh / sla-check.sh / regression.sh / render-dashboard.sh
│   └── run/                        # 脚本运行态产物（board-data.js、triage-建议.json，不入库）
├── evidence/                       # 回归与验证证据（按工单分目录，不入库）
└── dashboard.html                  # 可视化看板（读 scripts/run/board-data.js，file:// 可直接打开）
```

### 落地步骤
- [x] 步骤 1：本总纲（MainAgent）
- [x] 步骤 2：维护看板、config、SLA/规则、模板（MainAgent，工作文档）
- [x] 步骤 3：委派子 Agent 编写 `triage.sh / sla-check.sh / regression.sh / render-dashboard.sh` + `dashboard.html`
- [x] 步骤 4：配置 cron「维护巡检」「SLA 检查」两个定时任务（已录入并在运行，见 §5）
- [x] 步骤 5：端到端演练（见 §8.1）：三脚本空态/判定/SLA 实测 + 看板 Safari 真实数据渲染；并以真实工单 M-0001 走完整「修复→回归→部署→归档」闭环
- [x] 步骤 6：提交 GitHub（`.ops/` 入库、脚本入库；运行态证据/日志/真值配置按 .gitignore 排除；commit `e49ec28` / `63d803d`）

### 8.1 端到端实测记录（2026-10-15，任务 #15）

在不部署、不碰生产数据前提下，对自动化链路做四阶段实测，全部通过：

- **A. 空态基线**：`triage.sh`（空 inbox）、`sla-check.sh`（跳过 DONE 单）、`render-dashboard.sh` 均正常；`bash -n` 三脚本语法全过；render 输出 tickets=1（归档 M-0001）、WIP=0、alerts=0。
- **B. triage 判定（5 用例，临时单测完即删）**：
  - 缺必填字段（复现/期望/实际/环境）→ `NEEDS-INFO` 并给 4 条追问；
  - 「打卡按钮没反应、打卡失败」→ P1、模块 `kaohe.html`；
  - 「新注册登录不了、全是别人数据、串号、安全漏洞」→ P0、模块 `auth.php`+`kaohe.html`，且明确标注「P0 仅关键词初判，必须 MainAgent 复核后方可抢占」（脚本不自动定 P0）；
  - 「错别字」→ P3；
  - 与未关闭 READY 单 100% 相似 → 判重挂 `duplicate-of`，不新开。
  - `--dry-run` 确认不落盘。
- **C. SLA 计时（4 用例，临时单测完即删）**：超时 P2 → 🔴 超时 74h45m、建议升 P1；超时 P1 → 🔴 超时 5h45m、建议升 P0；BLOCKED → ⏸️ 暂停不计时；新建单 → 🟢 正常。脚本只**建议**升级，不自动升级。由此确认 P0/P1 全天 24h、P2/P3 每日 09–22 顺延且**周末不暂停**（口径已回写 §4.1 与 `sla-policy.md`）。
- **D. dashboard 可视化**：本机无 Chromium，`artifact-preview` 仅出文本；改用 Safari 打开 `file://.../.ops/dashboard.html` 截图核对——双队列卡（维护 WIP 0/2、开发 WIP 0/1）、文件锁无、SLA 无告警、6 列维护泳道与开发泳道、P0 抢占空态、最近关闭归档表正确渲染 M-0001，中文正常、无版式错乱。数据链路 `board-data.js → DOM` 完整。

**清理与隔离**：所有临时测试单测完即删；运行态产物（`scripts/run/`、`evidence/`、`tickets/`、`audit/`）均被 `.gitignore` 排除；演练后 `git status` 干净。

**真值配置用法**：公开仓库的 `workflow.config.json` 用占位符（`YOUR_SSH_USER@YOUR_NAS_HOST_OR_IP`、`https://YOUR_DOMAIN/...`）。本机复制 `config/workflow.config.local.example.json` 为 `workflow.config.local.json` 填真值（NAS SSH、线上 URL、测试账号），脚本 `load_config` 深度合并覆盖占位，该文件不入库。

**工单 frontmatter 解析约定**：解析器对带引号的字符串值允许行尾注释（`status: "READY"  # 注释`）；不规范取值回退 `UNKNOWN`。

**遗留**：`regression.sh` 本轮未做全流程演练（M-0001 当时用 staging 手工场景完成回归）；后续可用 `--skip-online` / `--base-url` 在不部署前提下补一次。

---

## 9. 已知限制与后续演进（不假装已具备）

1. **无前端错误自动上报**：iPhone Safari「Script error」这类问题目前靠用户截图，无探针自动建工单。后续可在 `kaohe.html` 加 `window.onerror` 把错误 POST 到 `kaohe-api/error_collect.php`，自动入 inbox（这是 FEAT 工单，不是本次维护流能力）。
2. **非常驻**：例行维护巡检每天 09:13 一次（SLA 独立复核每 3 天 09:23 一次），非工作时段不唤醒，自动发现新单的延迟最坏约 1 天；P0 需在会话里即时触发，不必等巡检。
3. **回归以冒烟+staging 为主**：项目无单测框架，暂不承诺完整自动化覆盖；后续可补 PHP 接口测试集。
4. **SLA 告警渠道**：当前告警落在看板标红 + cron 唤醒会话提示；要推送到微信/邮件需另接通知通道（项目已有 SMTP 配置可复用，列后续）。
