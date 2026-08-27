<?php
/**
 * PHPUnit bootstrap —— 占位。
 *
 * ⚠️ 本文件目前**不可用**，是有意为之。
 *
 * 原因：WordPress 插件的集成测试需要 WP 测试套件 + 数据库，属于**环境决策**，
 * 超出执行层（CP3）的自行批准范围，且会改动用户机器环境。
 * 因此测试环境形态已提交待裁定，未擅自引入 Docker、MySQL 或 Composer 依赖。
 *
 * 见记忆仓：05_KNOWLEDGE/KNOWN_ISSUES/OPEN_ITEMS.md ②
 *
 * 候选方案：
 *   A. @wordpress/env（官方 Docker）        —— 需 Docker Desktop
 *   B. wp-cli scaffold plugin-tests + MySQL —— 需本地 MySQL
 *   C. brain/monkey + PHPUnit（纯单元测试）—— 无需真实 WP，覆盖不到真实钩子
 *   D. 仅 php -l + 结构自检，实机走真实站点 —— 零环境成本，无回归保护
 *
 * CP3 建议：C + D 起步，A 作为目标态。
 *
 * ---------------------------------------------------------------------------
 * 当前可用的检查（不需要本文件）：
 *
 *     php -l berlin-wp-comments.php
 *     php tests/structure-check.php
 * ---------------------------------------------------------------------------
 *
 * @package Berlin_WP_Comments
 */

fwrite(
	STDERR,
	"PHPUnit bootstrap 尚未配置——测试环境形态待裁定（见记忆仓 OPEN_ITEMS ②）。\n" .
	"当前可用：php tests/structure-check.php\n"
);

exit( 1 );
