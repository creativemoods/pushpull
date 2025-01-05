<?php
/**
 * Utils class.
 * @package PushPull
 */

namespace CreativeMoods\PushPull;

/**
 * Class Utils
 */
class Utils {
	/**
	 * Get elements between two ids
	 * Will not include $a but will include $b
	 *
	 * @param array $array
	 * @param string $a
	 * @param string $b
	 * @return array
	 */
	function getElementsBetweenIds(array $array, string $a, string $b): array {
		$result = [];
		$foundA = false;

		foreach ($array as $item) {
			if ($foundA) {
				$result[] = $item;
			}
			if (is_array($item)) {
				$id = $item['id'];
			} else {
				$id = $item->id;
			}
			if ($id == $a) {
				$foundA = true;
				array_pop($result); // Remove the object with id $a (if added)
			}
			if ($id == $b) {
				break;
			}
		}
	
		return $result;
	}
	
	/**
	 * Find object by property
	 * e.g. find $obj->$prop2() in $array[][$prop1]
	 *
	 * @param mixed $array
	 * @param mixed $prop1
	 * @param mixed $obj
	 * @param mixed $prop2
	 * @return mixed
	 */
	public function findObjectByProperty($array, $prop1, $obj, $prop2): mixed {
		foreach ($array as $el) {
		  if (is_object($el)) {
			if (method_exists($el, $prop1)) {
				$item1 = $el->$prop1();
			} else {
				$item1 = $el->$prop1;
			}
		  } elseif (is_array($el)) {
			$item1 = $el[$prop1];
		  } else {
			return false;
		  }
		  if (is_object($obj)) {
			if (method_exists($obj, $prop2)) {
				$item2 = $obj->$prop2();
			} else {
				$item2 = $obj->$prop2;
			}
		  } elseif (is_array($obj)) {
			$item2 = $obj[$prop2];
		  } else {
			return false;
		  }
		  if ($item1 === $item2) {
			return $el;
		  }
		}
	
		return false;
	  }
	
	/**
	 * returns a subset of an array
	 *
	 * @param array $haystack
	 * @param array $needle
	 * @return array
	 */
	function sub_array(array $haystack, array $needle): array
	{
		return array_intersect_key($haystack, array_flip($needle));
	}

	/**
	 * returns an array without the specified keys
	 *
	 * @param array $haystack
	 * @param array $needle
	 * @return array
	 */
	function array_without_keys(array $haystack, array $needle): array
	{
		return array_diff_key($haystack, array_flip($needle));
	}

	/**
	 * Get post by name
	 *
	 * @param string $name
	 * @param string $post_type
	 * @return \WP_Post|null
	 */
	public function getLocalPostByName(string $type, string $name) {
		$query = new \WP_Query([
			"post_type" => $type,
			"name" => $name
		]);

		return $query->have_posts() ? reset($query->posts) : null;
	}

	/**
	 * Get list of providers.
	 *
	 * @return array
	 */
	public function getProviders() {
		return [
			[
				'id' => 'github',
				'name' => 'GitHub',
				'proonly' => false,
				'url' => 'https://api.github.com',
				'disabledurl' => true,
			],
			[
				'id' => 'gitlab',
				'name' => 'GitLab',
				'proonly' => true,
				'url' => 'https://gitlab.com/api/v4',
				'disabledurl' => true,
			],
/*			[
				'id' => 'bitbucket',
				'name' => 'Bitbucket',
				'proonly' => true,
				'url' => 'https://api.bitbucket.org/2.0',
				'disabledurl' => true,
			],
			[
				'id' => 'custom',
				'name' => 'Custom',
				'proonly' => true,
				'url' => 'https://...',
				'disabledurl' => false,
			],*/
		];
	}
}
