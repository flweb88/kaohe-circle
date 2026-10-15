---
id: M-0000                 # 工单编号，triage 时分配，格式 M-####
title: ""                  # 一句话现象
status: INBOX              # INBOX/NEEDS-INFO/TRIAGED/READY/ASSIGNED/FIXING/REGRESSING/REWORK/DEPLOYING/VERIFYING/DONE/BLOCKED/NEEDS-HUMAN
priority: ""               # P0/P1/P2/P3，triage 后填
module: ""                 # kaohe.html / kaohe-api/xxx.php
created_at: ""             # ISO 8601
created_by: ""             # 报修人（用户/巡检/错误上报）
assigned_at: ""
due_response_at: ""
due_fix_at: ""
deployed_at: ""
closed_at: ""
rework_count: 0
duplicate_of: ""
blocked_reason: ""
file_lock: ""              # 持有锁的文件，如 kaohe.html
environment: ""
tags: []
---

# M-0000 标题

## 现象描述
- **复现步骤**：
  1.
  2.
  3.
- **期望结果**：
- **实际结果**：
- **影响范围**：（仅自己 / 多人 / 全部用户）
- **环境**：（HTTP/HTTPS、设备与浏览器、账号、线上版本号）
- **截图/报错**：

## triage 记录（MainAgent 填）
- 初判优先级：
- 关键词命中：
- 模块归属：
- 是否重复：
- SLA：响应 due / 修复 due
- 分派的子 Agent：

## 根因分析（子 Agent / MainAgent 填）

## 修复方案（diff 摘要）

## 回归记录
- [ ] php -l 全通过
- [ ] 关键接口冒烟通过
- [ ] staging 复现：修复前失败 → 修复后通过
- [ ] 版本号两处一致
- [ ] 数据修复已备份（如涉及）
- 证据目录：`.ops/evidence/M-0000/`
- 返工次数：0

## 部署与线上验证
- commit：
- 部署时间：
- 线上版本号核验：
- 关键页面/接口状态：

## 复核结论
- 复核人：MainAgent
- 结果：通过 / 返工
- 复核包：

## 处理时间线
- created_at → 各状态时间戳（自动追加）
