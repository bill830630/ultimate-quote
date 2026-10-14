<?php
/**
 * 線上更新：後台「外掛」頁顯示新版本並一鍵更新。
 *
 * 用 `vendor/plugin-update-checker/` 串接公開 repo bill830630/ultimate-quote，比對最新 GitHub
 * Release 與目前的 `Version:` 標頭，下載 Release 附的 zip 覆蓋安裝（做法同終極登入）。
 *
 * 排除：wp-config.php 定義 `ULTQ_DISABLE_UPDATES`，或網域雜湊值列在 repo 的
 * `update-config.json`（`disabled_site_hashes`，SHA-256，先轉小寫、去掉 www.）。
 * `ULTQ_GITHUB_TOKEN` 選填，只用來拉高 GitHub API 頻率上限。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Updater {

	const GITHUB_REPO_SLUG        = 'bill830630/ultimate-quote';
	const DISABLED_LIST_CACHE_KEY = 'ultq_update_disabled_sites';
	const DISABLED_LIST_CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		if ( defined( 'ULTQ_DISABLE_UPDATES' ) && ULTQ_DISABLE_UPDATES ) {
			return;
		}
		if ( self::is_current_site_excluded() ) {
			return;
		}

		require_once ULTQ_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/' . self::GITHUB_REPO_SLUG . '/',
			ULTQ_PLUGIN_FILE,
			'ultimate-quote'
		);

		if ( defined( 'ULTQ_GITHUB_TOKEN' ) && '' !== ULTQ_GITHUB_TOKEN ) {
			$checker->setAuthentication( ULTQ_GITHUB_TOKEN );
		}

		// 只抓 Release 附的 zip，不要退回 repo 原始碼壓縮檔（頂層資料夾名稱不對）
		$checker->getVcsApi()->enableReleaseAssets( '/\.zip($|[?&#])/i' );
	}

	/** 抓不到清單一律當作沒被排除（fail open），要確保不更新請用 ULTQ_DISABLE_UPDATES。 */
	private static function is_current_site_excluded() {
		$hashes = get_transient( self::DISABLED_LIST_CACHE_KEY );
		if ( ! is_array( $hashes ) ) {
			$hashes = self::fetch_disabled_site_hashes();
			set_transient( self::DISABLED_LIST_CACHE_KEY, $hashes, self::DISABLED_LIST_CACHE_TTL );
		}

		$host = preg_replace( '#^www\.#', '', strtolower( trim( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) );

		return in_array( hash( 'sha256', $host ), $hashes, true );
	}

	private static function fetch_disabled_site_hashes() {
		$args = array(
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'ultimate-quote-update-checker',
			),
			'timeout' => 8,
		);
		if ( defined( 'ULTQ_GITHUB_TOKEN' ) && '' !== ULTQ_GITHUB_TOKEN ) {
			$args['headers']['Authorization'] = 'token ' . ULTQ_GITHUB_TOKEN;
		}

		$response = wp_remote_get( 'https://api.github.com/repos/' . self::GITHUB_REPO_SLUG . '/contents/update-config.json', $args );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$config  = is_array( $payload ) && ! empty( $payload['content'] ) ? json_decode( base64_decode( $payload['content'] ), true ) : null;
		if ( ! is_array( $config ) || empty( $config['disabled_site_hashes'] ) || ! is_array( $config['disabled_site_hashes'] ) ) {
			return array();
		}

		return array_map( 'strtolower', array_map( 'trim', $config['disabled_site_hashes'] ) );
	}
}
