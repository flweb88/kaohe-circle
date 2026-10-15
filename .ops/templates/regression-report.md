---
ticket: M-0000
ran_at: ""
ran_by: ""
version: ""
result: ""                # PASS / FAIL
summary: ""
---

# 回归报告 M-0000

## 1. 静态检查
| 项 | 结果 | 说明 |
|---|---|---|
| PHP `php -l`（全部 kaohe-api） | ⬜ | |
| 前端语法/加载检查（kaohe.html） | ⬜ | |
| 版本号两处一致（rk-ver / footer） | ⬜ | |

## 2. 关键接口冒烟
| 接口/页面 | 期望 | 实际 | 结果 |
|---|---|---|---|
| kaohe.html 页面 | HTTP 200 | | ⬜ |
| kaohe-api/auth.php | 存在/可响应 | | ⬜ |
| kaohe-api/load.php | 存在/可响应 | | ⬜ |
| kaohe-api/save.php | 存在/可响应 | | ⬜ |
| （按模块追加） | | | ⬜ |

## 3. 缺陷复现验证（staging 隔离账号）
- 使用账号：
- 修复前复现：⬜ 能复现 / 描述
- 修复后验证：⬜ 通过 / 描述
- 证据截图/日志：`.ops/evidence/M-0000/`

## 4. 数据安全（如涉及数据修复）
- 生产数据是否备份：⬜ 备份文件名/时间戳
- 是否影响其他用户：⬜
- 备份恢复方式：

## 5. 回归范围影响面（自查）
- 改动文件：
- 可能受影响的关联功能：
- 是否需要全量回归（还是仅冒烟）：

## 结论
- **PASS** → 进入 DEPLOYING
- **FAIL** → 回 REWORK，记录失败项与原因，rework_count +1
