/**
 * Berlin WP Comments — 前端脚本
 *
 * ⚠️ 本文件按设计保持为空。
 *
 * CP1 立项裁定（约束 C5）：
 *   「JS 第一版甚至可以是 0 KB。回复可以直接使用 WordPress
 *     自带的 comment-reply 机制。」
 *
 * 因此 V1：
 *   - 插件自有 JS = 0 KB（本文件不入队）
 *   - 线程回复复用 WordPress 核心脚本 `comment-reply`
 *     （wp-includes/js/comment-reply.js，非本插件资源）
 *
 * 措辞澄清（防日后误读）：「V1 JS 0 KB」指**插件自有 JS 为 0**，
 * 不等于「页面上完全没有 JS」——启用线程回复时核心脚本仍会加载。
 *
 * 本文件仅作为结构占位存在（CP1 立项目录清单含 assets/js/）。
 * 若未来确有必须的交互，先评估能否用核心脚本或无 JS 方案达成；
 * 新增 JS 属于对 C5 的偏离，需 CP1 审计。
 */
