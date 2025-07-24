<?php

/**
 * Plugin Name:       Post IO
 * Description:       Reads & writes posts onto disk to be edited by a text editor.
 * Version:           0.0.0
 * Author:            jiaSheng
 */

if (!defined('ABSPATH')) exit;

const POSTS_DIRECTORY = WP_CONTENT_DIR . '/posts';
const POSTS_METADATA_FILE = POSTS_DIRECTORY . '/posts.json';

register_activation_hook(__FILE__, function () {
	$live_site_url = site_url();

	/** @var array{version:int,site:array{url:string}} */
	$metadata = json_decode(file_get_contents(POSTS_METADATA_FILE) ?: '[]', true) ?: [];
	$metadata['version'] ??= 1;
	$metadata['site'] ??= [];
	$metadata['site']['url'] ??= $live_site_url;

	$disk_site_url = $metadata['site']['url'];
	$should_migrate_site_url = $disk_site_url !== $live_site_url;
	if ($should_migrate_site_url) {
		$metadata['site']['url'] = $live_site_url;
	}

	$should_migrate = $should_migrate_site_url /* || rest */;
	if ($should_migrate) {
		$paths = glob(trailingslashit(POSTS_DIRECTORY) . '*.html');

		foreach ($paths as $path) {
			$content = file_get_contents($path);
			if ($content === false) continue;

			$migrated_content = $content;

			if ($should_migrate_site_url)
				$migrated_content = str_replace(
					$disk_site_url,
					$live_site_url,
					$migrated_content,
				);

			if ($migrated_content !== $content)
				file_put_contents($path, $migrated_content);
		}
	}

	file_put_contents(POSTS_METADATA_FILE, json_encode($metadata));

	$post_types = get_post_types(['public' => true], 'names');
	$post_types = array_merge($post_types, ['wp_block', 'wp_template', 'wp_template_part']);

	foreach ($post_types as $post_type) {
		$page = 1;
		$posts_per_page = 100;

		do {
			$query = new WP_Query([
				'post_type' => $post_type,
				'post_status' => ['publish'],
				'posts_per_page' => $posts_per_page,
				'paged' => $page,
				'no_found_rows' => false,
			]);

			$posts = $query->get_posts();

			foreach ($posts as $post) {
				if (is_excluded_post($post)) continue;

				update_mirrored_post(
					find_mirrored_post_path($post->ID),
					$post->post_content,
					$post
				);
			}

			$page++;
		} while ($page <= $query->max_num_pages);
	}
});

add_action('admin_init', function () {
	$post_id =
		isset($_GET['post']) ? $_GET['post']
		: (isset($_GET['postId']) ? $_GET['postId']
			: null);

	if (!$post_id) return;

	$post = get_post($post_id);
	if (!$post || is_excluded_post($post)) return;

	$path = find_mirrored_post_path($post->ID);
	if ($path) {
		$post_content = read_mirrored_post($path, $post);
		$qualified_name = get_qualified_name_from_mirrored_post_path($path);
		$short_name = get_short_name($qualified_name);

		commit_post($post->ID, $short_name, $post_content);
	} else {
		write_mirrored_post(
			get_mirrored_post_path($post->ID, get_qualified_post_name($post), $post->post_type),
			$post->post_content,
			$post,
		);
	}
});

add_action('save_post', function (int $post_id) {
	$post = get_post($post_id);
	if (!$post || is_excluded_post($post)) return;

	update_mirrored_post(
		find_mirrored_post_path($post->ID),
		$post->post_content,
		$post
	);
});

add_action('trash_post', function (int $post_id) {
	$post = get_post($post_id);

	if (!$post || is_excluded_post($post)) return;

	$path = find_mirrored_post_path($post->ID);
	if ($path) unlink($path);
});

add_action('delete_post', function (int $post_id) {
	$post = get_post($post_id);

	if (!$post || is_excluded_post($post)) return;

	$path = find_mirrored_post_path($post->ID);
	if ($path) unlink($path);
});

add_filter('the_content', function (string $content) {
	if (
		// is not admin
		!current_user_can('edit_posts')
	) return $content;

	$post_id = sniff_post_id();
	if (!$post_id) return $content;

	$post = get_post($post_id);
	if (!$post) return $content;

	$dependencies = [
		$post_id,
		...get_additional_dependencies($post)
	];
	$seen_ids = [];
	$contents = [];
	foreach ($dependencies as $dependency_id) {
		$dependency_path = find_mirrored_post_path($dependency_id);
		$dependency_post = get_post($dependency_id);
		$seen_ids[$dependency_id] = true;

		if ($dependency_path && !is_excluded_post($dependency_post)) {
			$mirrored_content = read_mirrored_post($dependency_path, $dependency_post);
			if (!$mirrored_content) continue;

			$content = $mirrored_content;
			$contents[] = $content;

			$qualified_name = get_qualified_name_from_mirrored_post_path($dependency_path);
			$short_name = get_short_name($qualified_name);

			if (
				$dependency_post->post_content !== $content
				|| $dependency_post->post_name !== $short_name
			) {
				commit_post($dependency_post->ID, $dependency_post->post_name, $content);
				$dependency_post->post_content = $content;
			}
		} else {
			$contents[] = $dependency_post->post_content;
		}
	}

	while (!empty($contents)) {
		$curr = array_pop($contents);
		preg_match_all(
			'/<!--\s*wp:block\s*\{.*?"ref"\s*:\s*(\d+).*?\}\s*\/-->/ms',
			$curr,
			$block_ref_matches
		);
		$block_ids = $block_ref_matches[1];
		if (empty($block_ids) || count($block_ids) <= 0) continue;

		foreach ($block_ids as $block_id) {
			if (isset($seen_ids[$block_id])) continue;
			$seen_ids[$block_id] = true;

			$block_path = find_mirrored_post_path($block_id);
			$block_post = get_post($block_id);

			if ($block_path && !is_excluded_post($block_post)) {
				$block_content = read_mirrored_post($block_path, $block_post);
				$contents[] = $block_content;
				if ($block_post->post_content !== $block_content)
					commit_post($block_post->ID, $block_post->post_name, $block_content);
			} else {
				$contents[] = $block_post->post_content;
			}
		}
	}

	return $content;
}, 0);

add_filter('the_content', function (string $content) {
	return preg_replace_callback('/class=".*?"/', function ($matches) {
		return preg_replace('/(&amp;)|(&#038;)/', '&', $matches[0]);
	}, $content);
}, 10);

function is_excluded_post(WP_Post $post)
{
	$is_excluded = $post->post_name === ''
		|| wp_is_post_revision($post)
		|| wp_is_post_autosave($post)
		|| $post->post_type === 'attachment'
		|| str_starts_with($post->post_type, 'wp_') && (
			$post->post_type !== 'wp_block'
			&& $post->post_type !== 'wp_template_part'
			&& $post->post_type !== 'wp_template'
		)
		|| str_starts_with($post->post_type, 'acf-');
	$is_excluded = apply_filters(
		'post_io/exclude_post',
		$is_excluded,
		$post
	);
	$is_excluded = !!$is_excluded;

	return $is_excluded;
}

function get_additional_dependencies(WP_Post $post)
{
	/** @var int[] */
	$dependencies = [];
	$dependencies = apply_filters(
		'post_io/additional_dependencies',
		$dependencies,
		$post
	);
	/** @var int[] */
	$dependencies = array_map(static function ($id) {
		return (int) $id;
	}, array_filter($dependencies, static function ($id) {
		return is_numeric($id);
	}));
	$dependencies = array_unique($dependencies);

	return $dependencies;
}

function commit_post(int $post_id, string $short_name, string $post_content)
{
	$post = get_post($post_id);
	$post->post_name = $short_name;
	$post->post_content = $post_content;
	$post->post_modified = current_time('mysql');

	wp_update_post($post);
}

function get_mirrored_post_path(int $post_id, string $qualified_name, string $post_type)
{
	$name = implode('+', array_map(function ($name) {
		return sanitize_title($name);
	}, explode('+', $qualified_name)));
	$type_prefix = $post_type === 'page' ? '' : "$post_type.";

	return POSTS_DIRECTORY . "/$type_prefix$name.$post_id.html";
}

function get_qualified_post_name(WP_Post|int $post)
{
	// traverse ancestors & create a "+" delimited strings of all their names
	$ancestor_ids = get_post_ancestors($post);
	$ancestor_names = array_map(function ($id) {
		return get_post($id)->post_name;
	}, $ancestor_ids);
	$names = array_merge(
		array_reverse($ancestor_names),
		[$post->post_name]
	);

	return implode('+', $names);
}

/** @return string|null */
function find_mirrored_post_path(int $post_id)
{
	if (!file_exists(POSTS_DIRECTORY))
		mkdir(POSTS_DIRECTORY);
	if ($handle = opendir(POSTS_DIRECTORY)) {
		while ($filename = readdir($handle)) {
			if (preg_match("/\.$post_id\.[a-zA-Z]*$/", $filename)) {
				$path = POSTS_DIRECTORY . "/$filename";
				goto found;
			}
		}
		closedir($handle);
	} else throw new Exception("Could not open directory " . POSTS_DIRECTORY);

	not_found:
	return null;

	found:
	return $path;
}

function update_mirrored_post(?string $path, string $post_content, \WP_Post $post)
{
	if (!$path) {
		write_mirrored_post(
			get_mirrored_post_path(
				$post->ID,
				get_qualified_post_name($post),
				$post->post_type
			),
			$post_content,
			$post,
		);

		return;
	}

	$qualified_name = get_qualified_name_from_mirrored_post_path($path);
	$short_name = get_short_name($qualified_name);
	if (
		filesize($path) !== strlen($post_content)
		|| read_mirrored_post($path, $post) !== $post_content
	) {
		write_mirrored_post(
			get_mirrored_post_path($post->ID, $qualified_name, $post->post_type),
			$post_content,
			$post,
		);
	}

	if ($post->post_name !== $short_name) {
		$stale_path = get_mirrored_post_path(
			$post->ID,
			get_qualified_post_name($post),
			$post->post_type
		);

		if (file_exists($stale_path))
			unlink($stale_path);
	}
}

function write_mirrored_post(string $path, string $post_content, \WP_Post $post)
{
	$serialized_post_content = serialize_post_content($path, $post_content, $post);
	file_put_contents($path, $serialized_post_content);
}

function read_mirrored_post(string $path, \WP_Post $post)
{
	$post_content = file_get_contents($path);
	if ($post_content === false) return null;

	$deserialized_post_content = deserialize_post_content($path, $post_content, $post);

	return $deserialized_post_content;
}

function serialize_post_content(string $path, string $post_content, \WP_Post $post)
{
	return apply_filters(
		'post_io/serialize_post_content',
		$post_content,
		$path,
		$post,
	);
}

function deserialize_post_content(string $path, string $post_content, \WP_Post $post)
{
	return apply_filters(
		'post_io/deserialize_post_content',
		$post_content,
		$path,
		$post,
	);
}

function get_id_from_mirrored_post_path(string $path)
{
	preg_match('/\.(\d+)\.[a-zA-Z]*$/', basename($path), $matches);

	return $matches[1];
}

function get_qualified_name_from_mirrored_post_path(string $path)
{
	preg_match('/^(?:[^.]*\.)?(.*?)\.\d+\.[a-zA-Z]*$/', basename($path), $matches);

	return $matches[1];
}

function get_short_name(string $qualified_name)
{
	$names = explode('+', $qualified_name);

	return end($names);
}

function sniff_post_id()
{
	if (!current_user_can('edit_posts'))
		return get_post()?->ID;

	$post_id = null;
	$post = get_post();
	if ($post)
		if ($post->post_type === 'revision') {
			$post_id = $post->post_parent;
		} else if ($post->post_type === 'wp_navigation') {
			// ignore wp-admin/site-editor.php
		} else {
			$post_id = $post->ID;
		}
	if (!is_numeric($post_id))
		$post_id = $_GET['post'] ?? null;
	if (!is_numeric($post_id))
		$post_id = $_GET['post_id'] ?? null;
	if (!is_numeric($post_id))
		$post_id = $_GET['postId'] ?? null;
	if (!is_numeric($post_id)) {
		$p = $_GET['p'] ?? null;
		if ($p) {
			preg_match('/^\/wp_block\/(\d+)$/', $p, $matches);
			if (count($matches) > 1)
				$post_id = $matches[1];
		}
	}
	if (!is_numeric($post_id))
		return null;

	return (int) $post_id;
}
