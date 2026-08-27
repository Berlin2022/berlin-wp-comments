# Berlin WP Comments

极简 WordPress 原生评论增强插件。

- **定位**：Shortcode + Local Avatar + Native Comments（短代码 + 本地头像 + 原生评论）
- **核心原则**：WordPress 拥有评论数据与生命周期，插件只负责「呈现层」与「本地头像」，绝不重写评论提交机制。
- **当前状态**：`INITIATED`（仅项目初始化 + 插件骨架，**功能未实现**）。详见项目记忆仓库 `Berlin_wp_comments_memory`。

## 架构基线（CP1 裁定）

| 项 | 决策 |
| --- | --- |
| 评论数据 | 使用 `wp_comments` / `wp_commentmeta`，不自建表 |
| 提交机制 | 沿用原生 `comment_form()` → `/wp-comments-post.php` |
| 评论呈现 | 通过 `[wp_comments]` 短代码渲染，避免 `comments_template()` 加载主题 `comments.php` |
| 头像 | 本地头像，通过 `get_avatar_data` 过滤器接管，**零 Gravatar 依赖** |
| 头像模式 | V1 = 模式 A（基于用户/邮箱生成动态本地头像），V1 不含游客头像上传 |

## 目录结构

```
berlin-wp-comments/
├── berlin-wp-comments.php        # 主文件：常量、引导、激活/停用钩子（占位）
├── includes/
│   ├── class-plugin.php          # 单例装配器（骨架）
│   ├── class-avatar.php          # 本地头像（钩子未接线，骨架期零副作用）
│   ├── class-comments-renderer.php
│   ├── class-comment-form.php
│   └── class-comments-shortcode.php  # add_shortcode 已注册（可验证接线）
├── templates/                    # 结构占位模板（含 CP1 目标布局说明）
├── assets/css/comments.css       # .bwpc* 类名契约（暂无实际规则）
├── assets/js/comments.js         # V1 = 0 KB，复用 WP 核心 comment-reply
└── tests/
    ├── structure-check.php       # 静态结构 + 架构不变量自检（无需 WP）
    └── bootstrap.php             # PHPUnit 占位（见 OPEN_ITEMS ②）
```

## 开发自检

```bash
# 静态结构 + 架构不变量检查（无需 WordPress / 数据库 / PHPUnit）
php tests/structure-check.php
```

## 实现计划

见 `Berlin_wp_comments_memory/03_PLAN/CP2/CP2-001*.md`（等待 CP1 审计）。

## License

GPL-2.0-or-later（占位，待 CP1/USER 最终裁定，见 OPEN_ITEMS ⑥）。
