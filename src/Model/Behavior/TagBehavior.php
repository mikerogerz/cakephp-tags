<?php
namespace Tags\Model\Behavior;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Datasource\QueryInterface;
use Cake\Event\Event;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Cake\ORM\Table;
use Cake\Utility\Inflector;
use RuntimeException;

class TagBehavior extends Behavior {

	/**
	 * Configuration.
	 *
	 * @var array
	 */
	protected $_defaultConfig = [
		'field' => 'tag_list',
		'strategy' => 'string',
		'delimiter' => ',',
		'separator' => null,
		'namespace' => null,
		'tagsAlias' => 'Tags',
		'tagsAssoc' => [
			'className' => 'Tags.Tags',
			'joinTable' => 'tags_tagged',
			'foreignKey' => 'fk_id',
			'targetForeignKey' => 'tag_id',
			'propertyName' => 'tags',
		],
		'tagsCounter' => ['counter'],
		'taggedAlias' => 'Tagged',
		'taggedAssoc' => [
			'className' => 'Tags.Tagged',
		],
		'taggedCounter' => [
			'tag_count' => [
				'conditions' => [
				]
			]
		],
		'implementedEvents' => [
			'Model.beforeMarshal' => 'beforeMarshal',
			'Model.beforeFind' => 'beforeFind',
		],
		'implementedMethods' => [
			'normalizeTags' => 'normalizeTags',
		],
		'implementedFinders' => [
			'tagged' => 'findByTag'
		],
		'finderField' => 'tag',
		'fkModelField' => 'fk_model'
	];

	/**
	 * Merges config with the default and store in the config property
	 *
	 * @param \Cake\ORM\Table $table The table this behavior is attached to.
	 * @param array $config The config for this behavior.
	 */
	public function __construct(Table $table, array $config = []) {
		$this->_defaultConfig = (array)Configure::read('Tags') + $this->_defaultConfig;

		parent::__construct($table, $config);
	}

	/**
	 * Initialize configuration.
	 *
	 * @param array $config Configuration array.
	 * @return void
	 */
	public function initialize(array $config) {
		$this->bindAssociations();
		$this->attachCounters();
	}

	/**
	 * Return lists of event's this behavior is interested in.
	 *
	 * @return array Events list.
	 */
	public function implementedEvents() {
		return $this->config('implementedEvents');
	}

	/**
	 * Before marshal callback
	 *
	 * @param \Cake\Event\Event $event The Model.beforeMarshal event.
	 * @param \ArrayObject $data Data.
	 * @param \ArrayObject $options Options.
	 * @return void
	 */
	public function beforeMarshal(Event $event, ArrayObject $data, ArrayObject $options) {
		$field = $this->config('field') ?: $this->config('tagsAssoc.propertyName');
		$options['accessibleFields']['tags'] = true;

		if (!empty($data[$field])) {
			$data['tags'] = $this->normalizeTags($data[$field]);
		} elseif ($field !== 'tags') {
			if (isset($data['tags']) && is_string($data['tags'])) {
				throw new RuntimeException('Your `tags` property is malformed (expected array instead of string). You configured to save list of tags in `' . $field . '` field.');
			}
		}

		if (isset($data[$field]) && empty($data[$field])) {
			unset($data[$field]);
		}
	}

	/**
	 * @param \Cake\Event\Event $event
	 * @param \Cake\ORM\Query $query
	 * @param \ArrayObject $options
	 * @return \Cake\ORM\Query
	 */
	public function beforeFind(Event $event, Query $query, ArrayObject $options) {
		$query->formatResults(function ($results) {
			/** @var \Cake\Collection\CollectionInterface $results */
			return $results->map(function ($row) {
				$field = $this->_config['field'];

				if (!$row instanceOf Entity && !isset($row['tags'])) {
					return $row;
				}

				$row[$field] = $this->prepareTagsForOutput((array)$row['tags']);
				if ($row instanceOf Entity) {
					$row->setDirty($field, false);
				}
				return $row;
			});
		});

		return $query;
	}

	/**
	 * Generates comma-delimited string of tag names from tag array(), needed for
	 * initialization of data for text input
	 *
	 * @param array|null $data Tag data array to convert to string.
	 * @return string|array
	 */
	public function prepareTagsForOutput(array $data) {
		$tags = [];

		foreach ($data as $tag) {
			if ($this->_config['namespace']) {
				$tags[] = $tag['namespace'] . $this->_config['separator'] . $tag['label'];
			} else {
				$tags[] = $tag['label'];
			}
		}

		if ($this->_config['strategy'] === 'array') {
			return $tags;
		}

		return implode($this->_config['delimiter'] . ' ', $tags);
	}

	/**
	 * Binds all required associations if an association of the same name has
	 * not already been configured.
	 *
	 * @return void
	 */
	public function bindAssociations() {
		$config = $this->config();
		$tagsAlias = $config['tagsAlias'];
		$tagsAssoc = $config['tagsAssoc'];
		$taggedAlias = $config['taggedAlias'];
		$taggedAssoc = $config['taggedAssoc'];

		$table = $this->_table;
		$tableAlias = $this->_table->alias();

		$assocConditions = [$taggedAlias . '.' . $this->config('fkModelField') => $table->alias()];

		if (!$table->association($taggedAlias)) {
			$table->hasMany($taggedAlias, $taggedAssoc + [
				'foreignKey' => $tagsAssoc['foreignKey'],
				'conditions' => $assocConditions,
			]);
		}

		if (!$table->association($tagsAlias)) {
			$table->belongsToMany($tagsAlias, $tagsAssoc + [
				'through' => $table->{$taggedAlias}->target(),
				'conditions' => $assocConditions
			]);
		}

		if (!$table->{$tagsAlias}->association($tableAlias)) {
			$table->{$tagsAlias}
				->belongsToMany($tableAlias, [
					'className' => $table->table(),
				] + $tagsAssoc);
		}

		if (!$table->{$taggedAlias}->association($tableAlias)) {
			$table->{$taggedAlias}
				->belongsTo($tableAlias, [
					'className' => $table->table(),
					'foreignKey' => $tagsAssoc['foreignKey'],
					'conditions' => $assocConditions,
					'joinType' => 'INNER',
				]);
		}

		if (!$table->{$taggedAlias}->association($tableAlias . $tagsAlias)) {
			$table->{$taggedAlias}
				->belongsTo($tableAlias . $tagsAlias, [
					'className' => $tagsAssoc['className'],
					'foreignKey' => $tagsAssoc['targetForeignKey'],
					'conditions' => $assocConditions,
					'joinType' => 'INNER',
				]);
		}
	}

	/**
	 * Attaches the `CounterCache` behavior to the `Tagged` table to keep counts
	 * on both the `Tags` and the tagged entities.
	 *
	 * @return void
	 * @throws \RuntimeException If configured counter cache field does not exist in table.
	 */
	public function attachCounters() {
		$config = $this->config();
		$tagsAlias = $config['tagsAlias'];
		$taggedAlias = $config['taggedAlias'];

		$taggedTable = $this->_table->{$taggedAlias};

		if (!$taggedTable->hasBehavior('CounterCache')) {
			$taggedTable->addBehavior('CounterCache');
		}

		$counterCache = $taggedTable->behaviors()->CounterCache;

		if (!$counterCache->config($tagsAlias)) {
			$counterCache->config($tagsAlias, $config['tagsCounter']);
		}

		if ($config['taggedCounter'] === false) {
			return;
		}

		foreach ($config['taggedCounter'] as $field => $o) {
			if (!$this->_table->hasField($field)) {
				throw new RuntimeException(sprintf(
					'Field "%s" does not exist in table "%s"',
					$field,
					$this->_table->table()
				));
			}
		}
		if (!$counterCache->config($taggedAlias)) {
			//$field = key($config['taggedCounter']);
			$config['taggedCounter']['tag_count']['conditions'] = [
				$taggedTable->aliasField($this->config('fkModelField')) => $this->_table->alias()
			];
			$counterCache->config($this->_table->alias(), $config['taggedCounter']);
		}
	}

	/**
	 * Finder method
	 *
	 * Usage:
	 *   $query->find('tagged', ['{finderField}' => 'example-tag']);
	 *
	 * @param \Cake\ORM\Query $query
	 * @param array $options
	 * @return \Cake\ORM\Query
	 */
	public function findByTag(Query $query, array $options) {
		if (!isset($options[$this->config('finderField')])) {
			throw new RuntimeException('Key not present');
		}
		$slug = $options[$this->config('finderField')];
		if (empty($slug)) {
			return $query;
		}
		$query->matching($this->config('tagsAlias'), function (QueryInterface $q) use ($slug) {
			return $q->where([
				$this->config('tagsAlias') . '.slug' => $slug,
			]);
		});

		return $query;
	}

	/**
	 * Normalizes tags.
	 *
	 * @param array|string $tags List of tags as an array or a delimited string (comma by default).
	 * @return array Normalized tags valid to be marshaled.
	 */
	public function normalizeTags($tags) {
		if (is_string($tags)) {
			$tags = explode($this->config('delimiter'), $tags);
		}

		$result = [];

		$common = ['_joinData' => [$this->config('fkModelField') => $this->_table->alias()]];
		$namespace = $this->config('namespace');
		if ($namespace) {
			$common += compact('namespace');
		}

		$tagsTable = $this->_table->{$this->config('tagsAlias')};
		$pk = $tagsTable->primaryKey();
		$df = $tagsTable->displayField();

		foreach ($tags as $tag) {
			$tag = trim($tag);
			if (empty($tag)) {
				continue;
			}
			$tagKey = $this->_getTagKey($tag);
			$existingTag = $this->_tagExists($tagKey);
			if (!empty($existingTag)) {
				$result[] = $common + ['id' => $existingTag];
				continue;
			}
			list($id, $label) = $this->_normalizeTag($tag);
			$result[] = $common + compact(empty($id) ? $df : $pk) + [
				'slug' => $tagKey,
			];
		}

		return $result;
	}

	/**
	 * Generates the unique tag key.
	 *
	 * @param string $tag Tag label.
	 * @return string
	 */
	protected function _getTagKey($tag) {
		return strtolower(Inflector::slug($tag));
	}

	/**
	 * Checks if a tag already exists and returns the id if yes.
	 *
	 * @param string $tag Tag key.
	 * @return null|int
	 */
	protected function _tagExists($tag) {
		$tagsTable = $this->_table->{$this->config('tagsAlias')}->target();
		$result = $tagsTable->find()
			->where([
				$tagsTable->aliasField('slug') => $tag,
			])
			->select([
				$tagsTable->aliasField($tagsTable->primaryKey())
			])
			->first();
		if (!empty($result)) {
			return $result->id;
		}
		return null;
	}

	/**
	 * Normalizes a tag string by trimming unnecessary whitespace and extracting the tag identifier
	 * from a tag in case it exists.
	 *
	 * @param string $tag Tag.
	 * @return array The tag's ID and label.
	 */
	protected function _normalizeTag($tag) {
		$namespace = null;
		$label = $tag;
		$separator = $this->config('separator');
		if (strpos($tag, $separator) !== false) {
			list($namespace, $label) = explode($separator, $tag);
		}

		return [
			trim($namespace),
			trim($label)
		];
	}

}
