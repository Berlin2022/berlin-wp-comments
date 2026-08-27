# Changelog

本项目遵循语义化版本（SemVer）。所有条目按时间倒序排列。

## [0.1.0] — UNRELEASED (INITIATED)

> 阶段：项目初始化 + 插件骨架。**功能未实现**，所有方法为占位 stub，均带 `TODO[D<n>]` 标记。

- 项目立项（Berlin WP Comments），确立「原生评论 + 本地头像 + 短代码」架构基线。
- 建立双仓库拓扑：代码仓库（`Berlin_wp_comments`）+ 记忆仓库（`Berlin_wp_comments_memory`）。
- 创建插件骨架：主文件 + 5 个类 + 3 个模板 + 资源占位 + 静态自检脚本。
- 确立 9 条 V1 目标（O1–O9）与 6 条治理原则（继承 BerlinOS）。
- 登记 6 个开放项（OPEN_ITEMS ①–⑥），待 CP1/USER 裁定。

### 待办（骨架期之后）

- 实现本地头像过滤器接线（先经 CP1 审计 D8 约束）。
- 实现短代码渲染与分页。
- 补齐 PHPUnit 测试基线（OPEN_ITEMS ②）。
