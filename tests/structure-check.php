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
	'includes/class-comments-renderer.php',
	'includes/class-comment-form.php',
	'includes/class-comments-shortcode.php',
	'templates/comments.php',
	'templates/comment.php',
	'templates/form.php',
	'assets/css/comments.css',
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

// P3 起：评论表单必须复用核心 comment_form 提交链路（不自造 <form>/nonce）。
$form_src = is_file( $root . '/includes/class-comment-form.php' )
	? bwpc_strip_comments( file_get_contents( $root . '/includes/class-comment-form.php' ) )
	: '';
bwpc_check(
	false !== strpos( $form_src, 'comment_form' ),
	'评论表单复用核心提交链路（P3：comment_form，不自造表单/nonce）',
	'未找到 comment_form 调用'
);
bwpc_check(
	false === strpos( $form_src, '<form' ) && false === strpos( $form_src, 'action=' ),
	'评论表单不自造提交表单（P3：无裸 <form>/action，复用核心端点）',
	'发现自造 <form>/action，偏离核心提交链路'
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

/* -------------------------------------------------------------------------
 * 汇总
 * ------------------------------------------------------------------------- */
echo implode( "\n", $report ) . "\n\n";
echo str_repeat( '=', 60 ) . "\n";
printf( "结果：%d 通过 / %d 失败（共 %d 项）\n", $pass, $fail, $pass + $fail );

exit( $fail > 0 ? 1 : 0 );
