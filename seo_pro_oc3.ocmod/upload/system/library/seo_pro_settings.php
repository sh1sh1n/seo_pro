<?php
// The four SEO settings of the forms setting/setting and setting/store.
// The state is read once, when the object is created, and is not read again: one object per controller.
class Seo_pro_settings {
	private $language;
	private $state = array();

	// Each setting comes from the first source that has its key: the form, the store itself, the default store, the default value.
	// A wrong value is an error and is never replaced by the next source. $store_id: null for a new store.
	public function __construct($model_setting_setting, $language, $post, $store_id) {
		$this->language = $language;

		$sources = array();

		if (is_array($post)) {
			$sources[] = $post;
		}

		if ($store_id) {
			$sources[] = $model_setting_setting->getSetting('config', $store_id);
		}

		$sources[] = $model_setting_setting->getSetting('config', 0);

		$sources[] = array(
			'config_seo_url'              => '0',
			'config_seo_url_type'         => 'seo_url',
			'config_seo_url_include_path' => '0',
			'config_seo_url_postfix'      => ''
		);

		foreach (array_keys(end($sources)) as $key) {
			foreach ($sources as $source) {
				if (is_array($source) && array_key_exists($key, $source)) {
					$value = self::normalize($key, $source[$key]);

					break;
				}
			}

			$this->state[$key] = array('value' => $value, 'valid' => $value !== null);
		}
	}

	// The stored form of a value, or null when the value is not allowed
	private static function normalize($key, $value) {
		if ($key == 'config_seo_url' || $key == 'config_seo_url_include_path') {
			return ($value === 0 || $value === 1 || $value === '0' || $value === '1') ? (string)$value : null;
		}

		if ($key == 'config_seo_url_type') {
			return ($value === 'seo_url' || $value === 'seo_pro') ? $value : null;
		}

		return is_string($value) ? $value : null;
	}

	// Adds the errors of the wrong values to the errors of the controller
	public function validate(&$error) {
		foreach ($this->state as $key => $setting) {
			if (!$setting['valid']) {
				$error[substr($key, 7)] = $this->language->get('error_' . substr($key, 7));
			}
		}
	}

	// editSetting() deletes the whole group config: the settings the form did not send are saved again
	public function complete($post) {
		foreach ($this->state as $key => $setting) {
			if ($setting['valid']) {
				$post[$key] = $setting['value'];
			}
		}

		return $post;
	}

	public function form($data) {
		foreach ($this->state as $key => $setting) {
			// Request::clean() has escaped the value once: the same single layer for a value of any origin
			$data[$key] = $setting['valid'] ? htmlspecialchars(htmlspecialchars_decode($setting['value'], ENT_COMPAT), ENT_COMPAT, 'UTF-8') : '';
			$data[$key . '_invalid'] = !$setting['valid'];
			$data['error_' . substr($key, 7)] = $setting['valid'] ? '' : $this->language->get('error_' . substr($key, 7));
		}

		$data['seo_types'] = array();
		$data['seo_types'][] = array('type' => 'seo_url', 'name' => $this->language->get('text_seo_url'));
		$data['seo_types'][] = array('type' => 'seo_pro', 'name' => $this->language->get('text_seo_pro'));

		return $data;
	}
}
