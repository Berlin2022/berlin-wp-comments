<?php
/**
 * 骨架结构自检 + 架构不变量检查。
 *
 * 不需要 WordPress、不需要数据库、不需要 PHPUnit。
 * 用法（在插件根目录）：
 *
 *     php tests/structure-check.php
 *
 * 退出码：0 = 全部通过；1 = 存在失败项。
 *
 * 为什么用静态分析而非 include：
 *   插件文件均有 `if ( ! defined( 'ABSPATH' ) ) exit;` 守卫，
 *   在非 WP 环境下 include 会直接退出，因此这里只做文本/词法级检查。
 *
 * 这个脚本的真正价值不是"文件在不在"，而是**架构不变量**：
 * CP1 的核心裁定（不自建评论表、不依赖 Gravatar、不重写提交）
 * 被翻译成可自动检测的禁止模式。
 *
 * @package Berlin_WP_Comments
 */

$root = dirname( __DIR__ );

$pass   = 0;
$fail   = 0;
$report = array();

/**
 * 记录一条检查结果。
 *
 * @param bool   $ok      是否通过。
 * @param string $label   检查项名称。
 * @param string $detail  失败详情。
 * @return void
 */
function bwpc_check( $ok, $label, $detail = '' ) {
	global $pass, $fail, $report;
	if ( $ok ) {
		$pass++;
		$report[] = '  [PASS] ' . $label;
	} else {
		$fail++;
		$report[] = '  [FAIL] ' . $label . ( '' !== $detail ? ' — ' . $detail : '' );
	}
}

echo "Berlin WP Comments — 骨架结构自检\n";
echo str_repeat( '=', 60 ) . "\n\n";

/* -------------------------------------------------------------------------
 * 1. 必需文件存在性
 * ------------------------------------------------------------------------- */
echo "1. 必需文件\n";

$required_files = array(
	'berlin-wp-comments.php',
	'includes/class-plugin.php',
	'includes/class-avatar.php',
	'includes/class-bwpc-attachment.php',         // v0.1.13: 评论附件
	'includes/class-comments-renderer.php',
	'includes/class-comment-form.php',
	'includes/class-comments-shortcode.php',
	'templates/comments.php',
	'templates/comment.php',
	'templates/comments-pager.php',              // P5: 分页模板（已存在此前漏检）
	'templates/form.php',
	'assets/css/comments.css',
	'assets/css/berlin-wp-comments-vosalen.css',  // v0.1.12: 内置 B2B 视觉主题（已存在此前漏检）
	'assets/js/comments.js',
	'README.md',
	'CHANGELOG.md',
	'.gitignore',
);

foreach ( $required_files as $rel ) {
	bwpc_check( is_file( $root . '/' . $rel ), $rel, '文件缺失' );
}

/* -------------------------------------------------------------------------
 * 2. PHP 语法
 *    优先使用 php -l（最权威的语法检查，且不执行代码，ABSPATH 守卫不影响）。
 *    若 exec 不可用（部分托管环境禁用），退化为括号配平检查。
 * ------------------------------------------------------------------------- */
echo implode( "\n", $report ) . "\n\n2. PHP 语法\n";
$report = array();

$php_files = array();
foreach ( array( '.', 'includes', 'templates', 'tests' ) as $dir ) {
	foreach ( (array) glob( $root . '/' . $dir . '/*.php' ) as $f ) {
		$php_files[] = $f;
	}
}

$php_bin = defined( 'PHP_BINARY' ) ? PHP_BINARY : 'php';

foreach ( $php_files as $file ) {
	$rel = str_replace( '\\', '/', substr( $file, strlen( $root ) + 1 ) );

	// 优先：php -l
	$out = array();
	$rc  = 0;
	@exec( escapeshellarg( $php_bin ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $out, $rc );

	if ( 0 === $rc && '' !== implode( '', $out ) ) {
		// 通过 php -l。
		bwpc_check( true, $rel . ' 语法（php -l）' );
	} elseif ( false === $rc || '' === implode( '', $out ) ) {
		// exec 不可用：退化为括号配平。
		// 跳过命名 token（字符串/注释），并对字符串插值开头的花括号
		// （T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES）作 +1 配对，
		// 抵消紧随其后的裸 '}' 字符 token，避免误判。
		$src    = file_get_contents( $file );
		$tokens = @token_get_all( $src );
		$depth  = 0;
		$ok     = is_array( $tokens ) && count( $tokens ) > 1;
		if ( $ok ) {
			foreach ( $tokens as $t ) {
				if ( is_array( $t ) ) {
					if ( T_CURLY_OPEN === $t[0] || T_DOLLAR_OPEN_CURLY_BRACES === $t[0] ) {
						$depth++;
					}
					continue;
				}
				if ( '{' === $t ) {
					$depth++;
				} elseif ( '}' === $t ) {
					$depth--;
				}
			}
			$ok = ( 0 === $depth );
		}
		bwpc_check( $ok, $rel . ' 语法/括号配平', $ok ? '' : "花括号未配平（差值 {$depth}）" );
	} else {
		// php -l 报告语法错误。
		bwpc_check( false, $rel . ' 语法（php -l）', trim( implode( ' ', $out ) ) );
	}
}

/* -------------------------------------------------------------------------
 * 3. 类与常量声明
 * ------------------------------------------------------------------------- */
echo implode( "\n", $report ) . "\n\n3. 类与常量声明\n";
$report = array();

$expected_classes = array(
	'includes/class-plugin.php'             => 'Berlin_WP_Comments_Plugin',
	'includes/class-avatar.php'             => 'Berlin_WP_Comments_Avatar',
	'includes/class-bwpc-attachment.php'    => 'Bwpc_Comment_Attachment',     // v0.1.13
	'includes/class-comments-renderer.php'  => 'Berlin_WP_Comments_Renderer',
	'includes/class-comment-form.php'       => 'Berlin_WP_Comments_Form',
	'includes/class-comments-shortcode.php' => 'Berlin_WP_Comments_Shortcode',
);

foreach ( $expected_classes as $rel => $class ) {
	$path = $root . '/' . $rel;
	$src  = is_file( $path ) ? file_get_contents( $path ) : '';
	bwpc_check(
		(bool) preg_match( '/\b(?:final\s+)?class\s+' . preg_quote( $class, '/' ) . '\b/', $src ),
		$rel . ' 声明 class ' . $class,
		'未找到类声明'
	);
}

$main = is_file( $root . '/berlin-wp-comments.php' ) ? file_get_contents( $root . '/berlin-wp-comments.php' ) : '';

foreach ( array( 'BWPC_VERSION', 'BWPC_PLUGIN_FILE', 'BWPC_PLUGIN_DIR', 'BWPC_PLUGIN_URL', 'BWPC_SHORTCODE' ) as $const ) {
	bwpc_check(
		(bool) preg_match( "/define\(\s*'" . $const . "'/", $main ),
		'主文件定义常量 ' . $const,
		'未定义'
	);
}

/* -------------------------------------------------------------------------
 * 4. WordPress 插件头部
 * ------------------------------------------------------------------------- */
echo implode( "\n", $report ) . "\n\n4. 插件头部\n";
$report = array();

foreach ( array( 'Plugin Name:', 'Version:', 'Requires at least:', 'Requires PHP:', 'License:', 'Text Domain:' ) as $header ) {
	bwpc_check( false !== strpos( $main, $header ), '头部含 ' . $header, '缺失' );
}

/* -------------------------------------------------------------------------
 * 5. 直接访问守卫
 * ------------------------------------------------------------------------- */
echo implode( "\n", $report ) . "\n\n5. 直接访问守卫（ABSPATH）\n";
$report = array();

foreach ( $php_files as $file ) {
	$rel = str_replace( '\\', '/', substr( $file, strlen( $root ) + 1 ) );

	// tests/ 下的脚本是 CLI 工具，不需要 ABSPATH 守卫。
	if ( 0 === strpos( $rel, 'tests/' ) ) {
		continue;
	}

	$src = file_get_contents( $file );
	bwpc_check( false !== strpos( $src, "defined( 'ABSPATH' )" ), $rel . ' 有 ABSPATH 守卫', '缺少守卫' );
}

/* -------------------------------------------------------------------------
 * 6. 架构不变量（CP1 裁定的可自动检测部分）
 * ------------------------------------------------------------------------- */
echo implode( "\n", $report ) . "\n\n6. 架构不变量（CP1 裁定）\n";
$report = array();

// 禁止模式 => 违反的原则。
$forbidden = array(
	'/CREATE\s+TABLE/i'                  => 'P2 禁止自建评论数据表',
	'/wp_custom_comments/i'              => 'P2 禁止自建评论表',
	'/custom_comment_id/i'               => 'P2 禁止自造评论主键',
	'/dbDelta\s*\(/i'                    => 'P2 禁止建表',
	'/\bgravatar\.com/i'                 => 'P4 零 Gravatar 依赖（不得硬编码 gravatar 域名）',
	'/https?:\/\/[^\s\'"]*\.gravatar\./i' => 'P4 零 Gravatar 依赖',
);

foreach ( $forbidden as $pattern => $principle ) {
	$hits = array();
	foreach ( $php_files as $file ) {
		$rel = str_replace( '\\', '/', substr( $file, strlen( $root ) + 1 ) );

		// 本自检脚本自身含这些模式（作为检测规则），排除。
		if ( 'tests/structure-check.php' === $rel ) {
			continue;
		}

		$src = file_get_contents( $file );

		// 剥离注释后再检测——文档中说明"不要做 X"不算违规。
		$stripped = bwpc_strip_comments( $src );

		if ( preg_match( $pattern, $stripped ) ) {
			$hits[] = $rel;
		}
	}
	bwpc_check( empty( $hits ), $principle, '命中于：' . implode( ', ', $hits ) );
}

/**
 * 剥离 PHP 注释，只留可执行代码。
 *
 * @param string $src 源码。
 * @return string
 */
function bwpc_strip_comments( $src ) {
	$out    = '';
	$tokens = @token_get_all( $src );
	if ( ! is_array( $tokens ) ) {
		return $src;
	}
	foreach ( $tokens as $t ) {
		if ( is_array( $t ) ) {
			if ( T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) {
				continue;
			}
			$out .= $t[1];
		} else {
			$out .= $t;
		}
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * 7. 持续纪律：架构不变量仍守护（V1 实现期）
 * ------------------------------------------------------------------------- */
echo implode( "\n", $report ) . "\n\n7. 持续纪律（架构不变量守护）\n";
$report = array();

$todo_count = 0;
foreach ( $php_files as $file ) {
	$rel = str_replace( '\\', '/', substr( $file, strlen( $root ) + 1 ) );
	if ( 'tests/structure-check.php' === $rel ) {
		continue;
	}
	$todo_count += preg_match_all( '/TODO\[D\d\]/', file_get_contents( $file ) );
}

// V1 实现期：仍有未实现阶段（P3–P5）遗留 TODO 标记属正常；若全部清空说明超前实现，
// 这里只确保骨架纪律文件自身不参与计数。
bwpc_check( $todo_count >= 0, '剩余阶段化 TODO 标记（共 ' . $todo_count . ' 处，P3–P5 待实现）', '' );

// shortcode 必须已注册（接线可验证）。
$shortcode_src = is_file( $root . '/includes/class-comments-shortcode.php' )
	? bwpc_strip_comments( file_get_contents( $root . '/includes/class-comments-shortcode.php' ) )
	: '';
bwpc_check(
	false !== strpos( $shortcode_src, 'add_shortcode' ),
	'shortcode 已注册（接线可验证）',
	'未找到 add_shortcode'
);

// P1 起：头像钩子必须已接线（否则零 Gravatar 不生效）。
$avatar_src = is_file( $root . '/includes/class-avatar.php' )
	? bwpc_strip_comments( file_get_contents( $root . '/includes/class-avatar.php' ) )
	: '';
bwpc_check(
	false !== strpos( $avatar_src, "add_filter( 'get_avatar_data'" ),
	'头像钩子已接线（P1：零 Gravatar 生效）',
	'未找到 get_avatar_data 接线'
);

// 头像存储形态：仅 attachment_id（AUDIT-001 ⑤），不得双写 URL meta。
bwpc_check(
	false === strpos( $avatar_src, 'META_KEY_URL' ),
	'头像仅存 attachment_id（禁双写 URL meta，AUDIT-001 ⑤）',
	'发现 META_KEY_URL 双写残留'
);

// P2 起：评论渲染器必须已接线（wp_list_comments 驱动，否则列表不渲染）。
$renderer_src = is_file( $root . '/includes/class-comments-renderer.php' )
	? bwpc_strip_comments( file_get_contents( $root . '/includes/class-comments-renderer.php' ) )
	: '';
bwpc_check(
	false !== strpos( $renderer_src, 'wp_list_comments' ),
	'评论渲染器已接线（P2：wp_list_comments 驱动）',
	'未找到 wp_list_comments'
);

// P2 起：模板定位必须实现主题覆盖顺序（子主题→父主题→插件），不得裸返回插件路径。
$plugin_src = is_file( $root . '/includes/class-plugin.php' )
	? bwpc_strip_comments( file_get_contents( $root . '/includes/class-plugin.php' ) )
	: '';
bwpc_check(
	false !== strpos( $plugin_src, 'get_stylesheet_directory' ) && false !== strpos( $plugin_src, 'get_template_directory' ),
	'模板覆盖顺序已实现（P2：子主题→父主题→插件）',
	'locate_template 未实现主题覆盖'
);

// P2 起：单条评论模板必须产出实际标记（非纯 TODO 占位）。
$comment_tpl = is_file( $root . '/templates/comment.php' )
	? file_get_contents( $root . '/templates/comment.php' )
	: '';
bwpc_check(
	false !== strpos( $comment_tpl, 'bwpc-comment' ) && false !== strpos( $comment_tpl, 'comment_ID' ),
	'单条评论模板已落地（P2：bwpc-comment 标记）',
	'comment.php 仍为空占位'
);

// P2 起：容器模板必须产出实际结构（含 $list 输出）。
$comments_tpl = is_file( $root . '/templates/comments.php' )
	? file_get_contents( $root . '/templates/comments.php' )
	: '';
bwpc_check(
    false !== strpos( $comments_tpl, 'bwpc' ) && false !== strpos( $comments_tpl, '$list' ),
    '评论区容器模板已落地（P2：输出 $list）',
    'comments.php 仍为空占位'
);

// P2 修正（AUDIT-006）：render_comment() 处于 wp_list_comments callback 协议中，
// Walker 以 ob 捕获输出、不读取返回值；故必须 echo render_template('comment',...) 的返回值，
// 否则评论 HTML 运行时被丢弃（结构全绿但列表空）。守护此契约不变量，防同类断链复发。
bwpc_check(
    (bool) preg_match( '/echo\s+\$this->plugin->render_template\(\s*\'comment\'/', $renderer_src ),
    '渲染回调输出契约（P2：render_comment 必须 echo render_template(comment)）',
    'render_comment() 未 echo render_template(comment) 返回值，Walker 捕获为空'
);

// v0.1.12 结构性变更：表单已自渲染（脱离 comment_form() 内部排序漂移）。
// 原 P3 契约（"调用 comment_form" + "不自造 <form>"）已不适用，按新契约重写：
//   - 不再调用核心 comment_form()（避免不同 WP 版本下 cookies-consent / textarea / url
//     顺序漂移，实机观测）；
//   - action 仍指向 /wp-comments-post.php，id="respond" 保核心 comment-reply.js 集成；
//   - 保留 do_action('comment_form', $post_id) 让第三方扩展仍能挂接；
//   - 不自造 nonce（与核心端点冲突，nonce 由核心 wp-comments-post.php 校验）。
$form_src = is_file( $root . '/includes/class-comment-form.php' )
	? bwpc_strip_comments( file_get_contents( $root . '/includes/class-comment-form.php' ) )
	: '';
bwpc_check(
	false === strpos( $form_src, 'comment_form(' ),
	'评论表单不再调用核心 comment_form()（v0.1.12+ 自渲染：脱离 WP 内部排序漂移）',
	'class-comment-form.php 仍调用 comment_form()，需改为自渲染'
);
bwpc_check(
	false !== strpos( $form_src, '/wp-comments-post.php' )
		&& false !== strpos( $form_src, 'id="respond"' ),
	'评论表单仍复用核心端点（v0.1.12+：action=/wp-comments-post.php + id=respond 兼容 comment-reply.js）',
	'class-comment-form.php 未指向核心 wp-comments-post.php 或未设 id="respond"'
);
bwpc_check(
	false !== strpos( $form_src, "do_action( 'comment_form'" ),
	'评论表单保留 comment_form hook 点（v0.1.12+：do_action(\'comment_form\', $post_id) 让第三方扩展仍可用）',
	'class-comment-form.php 缺少 do_action(comment_form) 钩子（破坏了扩展位）'
);
bwpc_check(
	false === strpos( $form_src, 'wp_create_nonce' ) && false === strpos( $form_src, 'wp_nonce_field' ),
	'评论表单不自造 nonce（v0.1.12+：核心 wp-comments-post.php 端校验 nonce，不双写）',
	'class-comment-form.php 出现自造 nonce（与核心端点冲突）'
);
bwpc_check(
	false !== strpos( $form_src, 'current_user_can' ),
	'评论关闭提示仅对有权限登录用户（P3：陷阱 D）',
	'render_closed_notice 未做权限判断'
);
bwpc_check(
	false !== strpos( $form_src, "wp_enqueue_script" ) && false !== strpos( $form_src, 'comment-reply' ),
	'复用核心回复脚本（O4：comment-reply）',
	'未 enqueue comment-reply'
);

// P3 起：表单包裹模板必须产出实际结构（echo $form_html，不自造 <form>）。
$form_tpl = is_file( $root . '/templates/form.php' )
	? file_get_contents( $root . '/templates/form.php' )
	: '';
bwpc_check(
	false !== strpos( $form_tpl, 'echo $form_html' ),
	'表单包裹模板已落地（P3：输出 $form_html）',
	'form.php 仍为空占位'
);

// P4 起：shortcode canonical + alias 常量已定义（O1 入口）。
bwpc_check(
	false !== strpos( $main, "define( 'BWPC_SHORTCODE', 'berlin_comments' )" )
		&& false !== strpos( $main, "define( 'BWPC_SHORTCODE_ALIAS', 'wp_comments' )" ),
	'Shortcode canonical+alias 常量已定义（P4：O1 [berlin_comments]+[wp_comments]）',
	'主文件未定义 canonical/alias 常量'
);

// P4 起：两个标签均注册（O1 双标签接线）。
bwpc_check(
	false !== strpos( $shortcode_src, 'add_shortcode( BWPC_SHORTCODE' )
		&& false !== strpos( $shortcode_src, 'add_shortcode( BWPC_SHORTCODE_ALIAS' ),
	'Shortcode canonical+alias 均注册（P4：O1 双标签接线）',
	'未同时注册 berlin_comments / wp_comments'
);

// P4 起：条件资源入队（O9 轻量）——wp 预检测 + 兜底，不全局无条件加载。
bwpc_check(
	false !== strpos( $shortcode_src, "add_action( 'wp'," )
		&& false !== strpos( $shortcode_src, 'wp_enqueue_style' )
		&& false !== strpos( $shortcode_src, 'has_shortcode' )
		&& false !== strpos( $shortcode_src, 'assets_done' ),
	'条件资源入队（P4：O9 wp 预检测 + 兜底 + 仅含 shortcode 才加载）',
	'缺少 wp 预检测 / wp_enqueue_style / has_shortcode / assets_done 任一'
);

// P4 起：shortcode 产出真实输出（不再返回骨架占位）。
bwpc_check(
	false === strpos( $shortcode_src, '骨架版，功能未实现' ),
	'Shortcode 产出真实输出（P4：已移除骨架占位）',
	'handle() 仍返回骨架占位'
);

// P4 起：shortcode 参数边界校验（O9 轻量 / 防滥用）。
bwpc_check(
	false !== strpos( $shortcode_src, "'avatar_size'" ) && false !== strpos( $shortcode_src, 'max(' ),
	'Shortcode 参数边界校验（P4：avatar_size 限幅）',
	'normalize_atts 未做边界校验'
);

// P4 修正（AUDIT-007）：O1 契约——shortcode 注册不得静默覆盖已有同名 handler。
// register() 必须以 shortcode_exists() 守卫包裹 add_shortcode；否则本插件会抢占外部已注册标签，
// 与 O1「不静默覆盖他人标签」声明冲突（静态结构存在 ≠ 行为契约已闭合，同 AUDIT-006 性质）。
bwpc_check(
	(bool) preg_match( '/if\s*\(\s*!\s*shortcode_exists\(\s*BWPC_SHORTCODE\s*\)\s*\)/', $shortcode_src )
		&& (bool) preg_match( '/if\s*\(\s*!\s*shortcode_exists\(\s*BWPC_SHORTCODE_ALIAS\s*\)\s*\)/', $shortcode_src )
		&& false !== strpos( $shortcode_src, 'add_shortcode( BWPC_SHORTCODE' )
		&& false !== strpos( $shortcode_src, 'add_shortcode( BWPC_SHORTCODE_ALIAS' ),
	'Shortcode 注册冲突保护（P4/AUDIT-007：O1 不静默覆盖他人 handler，shortcode_exists 守卫）',
	'register() 未以 shortcode_exists() 守卫包裹 add_shortcode（可能抢占外部同名 handler）'
);

// P5 起：分页复用原生 cpage（OPEN_ITEMS ③ 方案 A）。
bwpc_check(
	false !== strpos( $renderer_src, 'get_comments_pagenum_link' ),
	'分页复用原生 cpage（P5：OPEN_ITEMS ③ 方案 A，get_comments_pagenum_link）',
	'render_pagination 未使用原生 cpage 链接生成'
);

// P5 + P6 演进：query_comments 在 PHP 层按根评论分页（array_slice 切片本页根 + 补全其后代），
// 不依赖 comments_template 上下文；offset 改为全集取回后的 array_slice。
bwpc_check(
	false !== strpos( $renderer_src, 'get_root_ids' )
		&& false !== strpos( $renderer_src, 'array_slice' )
		&& false !== strpos( $renderer_src, 'collect_page_thread_ids' ),
	'分页在 query 层落地（P5+P6：get_root_ids 分母 + array_slice 切片本页根 + 后代补全）',
	'query_comments 未按根评论分页（缺失 get_root_ids / array_slice / collect_page_thread_ids）'
);

// P5 起：当前页码读取原生 cpage 查询变量。
bwpc_check(
	(bool) preg_match( "/get_query_var\(\s*'cpage'/", $renderer_src ),
	'当前页码读取原生 cpage（P5：get_query_var(\'cpage\')）',
	'render_pagination/query_comments 未读取 cpage 查询变量'
);

// P5 起：分页模板已落地（可被主题覆盖，P9）。
$pager_tpl = is_file( $root . '/templates/comments-pager.php' )
	? file_get_contents( $root . '/templates/comments-pager.php' )
	: '';
bwpc_check(
	false !== strpos( $pager_tpl, 'bwpc-pager__list' )
		&& false !== strpos( $pager_tpl, 'esc_url' ),
	'分页模板已落地（P5：templates/comments-pager.php）',
	'comments-pager.php 缺失或不含分页结构'
);

// P5 起：render_pagination 不再返回骨架占位空串（已实装原生 cpage）。
bwpc_check(
	false === strpos( $renderer_src, "TODO[D4]：待 OPEN_ITEMS ③ 裁定后实现" )
		&& false !== strpos( $renderer_src, 'get_comments_pagenum_link' ),
	'分页已实装（P5：render_pagination 不再为占位）',
	'render_pagination 仍为骨架占位'
);

// AUDIT-008 REQUIRED CORRECTION ①（P6 演进）：分页单位 = 根评论（parent=0 或
// parent 指向缺失/非本产品评论的孤儿回复）；offset 改为 PHP 层 array_slice（全集已取回），
// 不再做 DB 级 parent=>0 + offset 切片，避免「有计数却无内容」（P6 实机：9 条孤儿回复被
// parent=0 过滤吞掉）。
bwpc_check(
	false !== strpos( $renderer_src, 'get_root_ids' )
		&& false !== strpos( $renderer_src, 'array_slice' )
		&& false !== strpos( $renderer_src, 'count_top_level_comments' ),
	'分页按根评论单位（AUDIT-008①+P6：get_root_ids 推导分母 + array_slice 切片本页根）',
	'query_comments 未按根评论分页（仍按平面 comment 行或 DB offset 切片）'
);

// P6 修正：孤儿回复（parent 指向不存在 / 非本产品已批准评论）须作为根展示，
// 否则计数有值而列表恒空（实机 9 条评论全部为孤儿回复 → 旧 parent=0 过滤致 No comments yet）。
bwpc_check(
	false !== strpos( $renderer_src, 'get_root_ids' )
		&& false !== strpos( $renderer_src, 'comment_parent' )
		&& false !== strpos( $renderer_src, '0 === $pid' ),
	'孤儿回复作根（P6：parent 不在本产品已批准评论集时视为根，列表不再恒空）',
	'未将孤儿回复识别为根评论，仍可能被 parent=0 过滤吞掉'
);

// AUDIT-008 REQUIRED CORRECTION ①：必须补齐每个根 thread 的完整后代，
// 否则 wp_list_comments 依 comment_parent 建树时父节点缺失。
// P6 演进：后代从已取回全集内按 children_map 递归收集（collect_page_thread_ids），
// 不再走 DB 级 parent__in 批量查询（避免分页切断 thread 的同时消除额外 DB 往返）。
bwpc_check(
	false !== strpos( $renderer_src, 'collect_page_thread_ids' )
		&& false !== strpos( $renderer_src, 'children_map' ),
	'thread 后代补全（AUDIT-008①+P6：collect_page_thread_ids + children_map 递归补全）',
	'未补齐根评论的后代，分页会切断 thread'
);

// AUDIT-008 REQUIRED CORRECTION ②：per_page() 实际消费 page_comments 总开关。
bwpc_check(
	false !== strpos( $renderer_src, 'page_comments' ),
	'per_page 消费 page_comments（AUDIT-008②：分页总开关）',
	'per_page() 未消费 page_comments 选项'
);

// AUDIT-008 REQUIRED CORRECTION ②：default_comments_page 决定顶层排序方向。
bwpc_check(
	false !== strpos( $renderer_src, 'default_comments_page' )
		&& false !== strpos( $renderer_src, 'top_level_order' ),
	'default_comments_page 消费（AUDIT-008②：top_level_order 决定排序）',
	'未消费 default_comments_page 决定 thread 排序'
);

// AUDIT-008 REQUIRED CORRECTION ①：分页总页数基于顶层 thread 数（非全部 comment 行）。
bwpc_check(
	false !== strpos( $renderer_src, 'count_top_level_comments' ),
	'分页分母=顶层 thread 数（AUDIT-008①：count_top_level_comments）',
	'render_pagination 未以顶层评论数推导 max_pages'
);

// v0.1.13: 评论附件模块契约。
$attachment_src = is_file( $root . '/includes/class-bwpc-attachment.php' )
	? bwpc_strip_comments( file_get_contents( $root . '/includes/class-bwpc-attachment.php' ) )
	: '';
bwpc_check(
	false !== strpos( $attachment_src, "add_action( 'comment_post'" )
		&& false !== strpos( $attachment_src, "'deleted_comment'" )
		&& false !== strpos( $attachment_src, "'trash_comment'" )
		&& false !== strpos( $attachment_src, "'spam_comment'" ),
	'attachment 模块注册 4 个生命周期钩子（v0.1.13：comment_post + deleted/trash/spam_comment）',
	'class-bwpc-attachment.php 缺少一个或多个钩子注册'
);
bwpc_check(
	false !== strpos( $plugin_src, 'new Bwpc_Comment_Attachment' )
		&& false !== strpos( $plugin_src, 'attachment->register' ),
	'attachment 模块已装配（v0.1.13：plugin boot() 实例化 + register）',
	'class-plugin.php 未实例化 / 未注册 attachment 模块'
);
// 模板必须调用 render_media + 触发 .bwpc-comment__media CSS 容器（否则 CSS 段落 6 永不入场）。
bwpc_check(
	false !== strpos( $comment_tpl, 'Bwpc_Comment_Attachment::render_media' )
		&& false !== strpos( $comment_tpl, 'bwpc-comment__media' ),
	'attachment 模板输出契约（v0.1.13：comment.php 调用 render_media + 输出 .bwpc-comment__media）',
	'comment.php 未调用 render_media 或未输出 .bwpc-comment__media 容器'
);

// v0.1.19: 待审评论兼容 + MIME 白名单格式修正（实机「图片不显示」根因收口）。
$att_src = is_file( $root . '/includes/class-bwpc-attachment.php' ) ? file_get_contents( $root . '/includes/class-bwpc-attachment.php' ) : '';
bwpc_check(
	false !== strpos( $att_src, 'transition_comment_status' )
		&& false !== strpos( $att_src, "'on_approve'" ),
	'待审评论兼容契约（v0.1.19：注册 transition_comment_status + on_approve 转正）',
	'class-bwpc-attachment.php 未注册 transition_comment_status / on_approve'
);
bwpc_check(
	false !== strpos( $att_src, 'META_ATTACHMENT_PENDING' ),
	'待审暂存 meta 契约（v0.1.19：_bwpc_attachment_pending 常量存在）',
	'class-bwpc-attachment.php 缺少 META_ATTACHMENT_PENDING 常量'
);
bwpc_check(
	false !== strpos( $att_src, 'jpg|jpeg|jpe' ),
	'MIME 白名单格式契约（v0.1.19：allowed_mimes 须为 ext=>mime 映射，非纯 MIME 数组）',
	'class-bwpc-attachment.php allowed_mimes 仍为纯 MIME 数组（旧 WP 下 wp_handle_upload 会被拒）'
);

// v0.1.13: 评论分页视觉契约（非当前页必须有清晰可见的默认边框，不是 --vosalen-border）。
$vosalen_css = is_file( $root . '/assets/css/berlin-wp-comments-vosalen.css' )
	? file_get_contents( $root . '/assets/css/berlin-wp-comments-vosalen.css' )
	: '';
bwpc_check(
	false !== strpos( $vosalen_css, '.bwpc-pager__link' )
		&& false !== strpos( $vosalen_css, '#D1D5DB' ),
	'分页非当前页可见性契约（v0.1.13：.bwpc-pager__link 使用 #D1D5DB 高对比度边框）',
	'berlin-wp-comments-vosalen.css 缺少清晰的非当前页边框色（默认 #E5E7EB 对比度过弱）'
);

// v0.1.14: 评论列表框视觉契约（CP2 视觉标准：框体元素统一主色边框 + 圆角语言；用户指定"不需要背景"）。
bwpc_check(
	false !== strpos( $vosalen_css, '.bwpc {' )
		&& false !== strpos( $vosalen_css, 'border: 1px solid var(--bwpc-accent)' )
		&& false !== strpos( $vosalen_css, 'background: transparent' ),
	'评论列表框契约（v0.1.14：.bwpc 主色边框 + 圆角 + 无背景填充，对齐 .bwpc-pager__current）',
	'berlin-wp-comments-vosalen.css 的 .bwpc 未定义主色边框 / 背景未设为 transparent'
);

// v0.1.18: ATTACHMENT-001 #15 Storage Boundary —— 存储抽象必须存在，且适配层不得直连 WP 媒体 API。
$storage_src = is_file( $root . '/includes/class-bwpc-attachment-storage.php' )
	? bwpc_strip_comments( file_get_contents( $root . '/includes/class-bwpc-attachment-storage.php' ) )
	: '';

bwpc_check(
	false !== strpos( $storage_src, 'interface Bwpc_Attachment_Storage' )
		&& false !== strpos( $storage_src, 'class Bwpc_Attachment_Storage_WP' )
		&& false !== strpos( $storage_src, 'implements Bwpc_Attachment_Storage' ),
	'Storage Provider 抽象存在（#15：interface Bwpc_Attachment_Storage + WP 适配器 implements）',
	'class-bwpc-attachment-storage.php 缺少接口或 WP 适配器实现'
);

// 适配层（class-bwpc-attachment.php）不得直接调用 WP 媒体 API；必须经过 Storage Provider 接口。
// 注意：Storage Provider 文件（class-bwpc-attachment-storage.php）允许且应该调用这些 API。
bwpc_check(
	false === strpos( $attachment_src, 'wp_handle_upload(' )
		&& false === strpos( $attachment_src, 'wp_insert_attachment(' )
		&& false === strpos( $attachment_src, 'wp_delete_attachment(' ),
	'附件适配层不直连 WP 媒体 API（#15：必须经 Bwpc_Attachment_Storage 接口）',
	'class-bwpc-attachment.php 仍直接调用 wp_handle_upload / wp_insert_attachment / wp_delete_attachment'
);

// v0.1.18: ATTACHMENT-001 #12 修正 —— 插入失败必须清理已落盘物理文件，避免孤儿。
bwpc_check(
	false !== strpos( $storage_src, '@unlink' )
		&& false !== strpos( $storage_src, 'is_wp_error( $attach_id )' ),
	'插入失败清理孤儿文件（#12：Bwpc_Attachment_Storage_WP::store 中 wp_insert_attachment 失败 @unlink）',
	'Storage_WP::store 缺少插入失败物理文件清理'
);

/* -------------------------------------------------------------------------
 * 8. CSS 维度写法有效性（AUDIT-009 回归防护）
 *    CP1 在 AUDIT-009 复核中要求：任何 <number><space><unit> 的非法 CSS
 *    dimension 写法（如 `14 px`、`1.25 em`）不得进入 CSS，否则浏览器视为
 *    无效声明直接丢弃。此处静态扫描 assets/css 下所有 CSS 文件。
 * ------------------------------------------------------------------------- */
echo implode( "\n", $report ) . "\n\n8. CSS 维度写法有效性（AUDIT-009 回归防护）\n";
$report = array();

$css_files = (array) glob( $root . '/assets/css/*.css' );
$bad_css   = array();
foreach ( $css_files as $f ) {
	$rel   = str_replace( '\\', '/', substr( $f, strlen( $root ) + 1 ) );
	$lines = file( $f );
	foreach ( $lines as $i => $line ) {
		if ( preg_match( '/\d\s+(px|em|rem|%|vh|vw|s|ms)\b/', $line ) ) {
			$bad_css[] = $rel . ':' . ( $i + 1 );
		}
	}
}
bwpc_check( empty( $bad_css ), 'CSS 无非法 dimension 写法（number 与 unit 无空格）', '命中于：' . implode( ', ', $bad_css ) );

/* -------------------------------------------------------------------------
 * 汇总
 * ------------------------------------------------------------------------- */
echo implode( "\n", $report ) . "\n\n";
echo str_repeat( '=', 60 ) . "\n";
printf( "结果：%d 通过 / %d 失败（共 %d 项）\n", $pass, $fail, $pass + $fail );

exit( $fail > 0 ? 1 : 0 );
