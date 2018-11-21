<?php
/**
    Tatoeba Project, free collaborative creation of languages corpuses project
    Copyright (C) 2016  Gilles Bedel

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Core\Configure;
use Cake\Database\Schema\TableSchema;
use Cake\Validation\Validator;


class AudiosTable extends Table
{
    public $validate = array(
        'sentence_id' => array(
            'validateType' => array(
                'rule' => 'numeric',
                'required' => true,
                'on' => 'create',
            ),
        ),
        'user_id' => array(
            'rule' => 'numeric',
            'allowEmpty' => true,
        ),
        'created' => array(
            'rule' => 'notBlank',
        ),
        'modified' => array(
            'rule' => 'notBlank',
        ),
    );

    public $actsAs = array('Containable');

    public $belongsTo = array(
        'Sentence' => array('type' => 'inner'),
        'User',
    );

    protected function _initializeSchema(TableSchema $schema)
    {
        $schema->setColumnType('external', 'json');
        $schema->setColumnType('modified', 'string');
        $schema->setColumnType('created', 'string');
        return $schema;
    }

    public function initialize(array $config)
    {
        $this->belongsTo('Sentences');

        $this->addBehavior('Timestamp');
        if (Configure::read('Search.enabled')) {
            $this->addBehavior('Sphinx', ['alias' => $this->getAlias()]);
        }
    }

    public function validationDefault(Validator $validator)
    {
        $validator
            ->requirePresence('sentence_id', 'create')
            ->numeric('sentence_id');

        $validator
            ->notBlank('created');

        $validator
            ->notBlank('modified');

        return $validator;
    }

    public function afterFind($results, $primary = false) {
        foreach ($results as &$result) {
            if (isset($result[$this->alias])
                && array_key_exists('external', $result[$this->alias])) {
                $result[$this->alias]['external'] = (array)json_decode(
                    $result[$this->alias]['external']
                );
                $result[$this->alias]['external'] = array_merge(
                    $this->defaultExternal,
                    $result[$this->alias]['external']
                );
            }
        }
        return $results;
    }

    private function encodeExternal($entity) {
        if ($entity && is_array($entity->external)) {
            $external = $this->get($entity->id, ['fields' => ['external']]);
            if ($external === false) {
                $external = array();
            }
            $external = array_merge($external, $this->data[$this->alias]['external']);
            $external = array_intersect_key($external, $this->defaultExternal);
            //$this->data[$this->alias]['external'] = json_encode($external);
        }
    }

    public function beforeSave($event, $entity, $options = array()) {
        $ok = true;
        $user_id = $entity->user_id;
        $external = $entity->external;
        if ($external) {
            $external = array_filter($external);
        }
        if (!($user_id xor !empty($external))) {
            $ok = false;
        }

        return $ok;
    }

    public function afterSave($event, $entity, $options = array()) {
        if ($entity->sentence_id) {
            $this->Sentences->flagSentenceAndTranslationsToReindex(
                $entity->sentence_id
            );
            $prev_sentence_id = $entity->getOriginal('sentence_id');
            if ($prev_sentence_id != $entity->sentence_id) {
                $this->Sentences->flagSentenceAndTranslationsToReindex(
                    $prev_sentence_id
                );
            }
        }
    }

    public function afterDelete($event, $entity, $options) {
        if ($entity->sentence_id) {
            $this->Sentences->flagSentenceAndTranslationsToReindex(
                $entity->sentence_id
            );
        }
    }

    public function sphinxAttributesChanged(&$attributes, &$values, &$isMVA, $entity) {
        $sentenceId = $entity->sentence_id;
        if ($sentenceId) {
            $attributes[] = 'has_audio';
            $hasAudio = $this->findBySentenceId($sentenceId)->first();
            $values[$sentenceId][] = $hasAudio ? 1 : 0;
        }
    }

    public function numberOfAudiosBy($userId) {
        return $this->find()
            ->where(['user_id' => $userId])
            ->count();
    }

    public function assignAudioTo($sentenceId, $ownerName, $allowExternal = true) {
        $data = array(
            'sentence_id' => $sentenceId,
            'user_id' => null,
            'external' => null,
        );

        $result = $this->User->findByUsername($ownerName);
        if ($result) {
            $data['user_id'] = $result[$this->User->alias]['id'];
        } elseif ($allowExternal && !empty($ownerName)) {
            $data['external'] = array('username' => $ownerName);
        }

        $result = $this->findBySentenceId($sentenceId, 'id');
        if ($result) { // reassign audio
            $data['id'] = $result[$this->alias]['id'];
        } else {
            $this->create();
        }

        return $this->save($data);
    }

    public function getFilesToImport() {
        $importPath = Configure::read('Recordings.importPath');
        $audioFiles = array();
        $allSentenceIds = array();

        $dh = opendir($importPath);
        if (!$dh) {
            return $audioFiles;
        }
        while (false !== ($filename = readdir($dh))) {
            $file = $importPath . DS . $filename;
            if (is_file($file)) {
                $fileInfos = array(
                    'fileName' => $filename,
                    'sourcePath' => $importPath.$filename,
                    'valid'    => false,
                );
                if (preg_match('/^(\d+)\.mp3$/i', $filename, $matches)) {
                    $fileInfos['sentenceId'] = $allSentenceIds[] = $matches[1];
                }
                $audioFiles[] = $fileInfos;
            }
        }
        closedir($dh);

        $sentences = $this->Sentence->find('all', array(
            'conditions' => array('Sentence.id' => $allSentenceIds),
            'fields' => array('id', 'lang'),
            'contain' => array('Audio'),
        ));
        $sentences = Set::combine($sentences, '{n}.Sentence.id', '{n}');

        foreach ($audioFiles as &$file) {
            if (isset($file['sentenceId'])) {
                $id = $file['sentenceId'];
                if (isset($sentences[$id])) {
                    $file['lang'] = $sentences[$id]['Sentence']['lang'];
                    $file['hasaudio'] = count($sentences[$id]['Audio']) > 0;
                    $file['valid'] = !is_null($sentences[$id]['Sentence']['lang']);
                }
            }
        }

        usort($audioFiles, function($a, $b) {
            /* Sort:
             * 1. May not be imported
             * 2. Already has audio
             * 3. The rest by sentence id
             */
            if (isset($a['valid']) && isset($b['valid'])
                && $a['valid'] != $b['valid']) {
                return $a['valid'] ? 1 : -1;
            } elseif (isset($a['hasaudio']) && isset($b['hasaudio'])
                      && $a['hasaudio'] != $b['hasaudio']) {
                return $a['hasaudio'] ? -1 : 1;
            } elseif (isset($a['sentenceId']) && isset($b['sentenceId'])) {
                return $a['sentenceId'] - $b['sentenceId'];
            } else {
                return 0;
            }
        });

        return $audioFiles;
    }

    public function importFiles(&$errors, $author) {
        $recsBaseDir = Configure::read('Recordings.path');
        $errors = array();
        $filesImported = array('total' => 0);

        $files = $this->getFilesToImport();
        foreach ($files as $file) {
            if (!$file['valid']) {
                $errors[] = format(
                    __d('admin', "Invalid file “{file}” ignored."),
                    array('file' => $file['fileName'])
                );
                continue;
            }

            $destDir = $recsBaseDir . DS . $file['lang'];
            if (!file_exists($destDir)) {
                if (!mkdir($destDir)) {
                    $errors[] = format(
                        __d('admin', "Failed to create directory “{dir}” to import file “{file}”."),
                        array('dir' => $destDir, 'file' => $file['fileName'])
                    );
                    continue;
                }
            }

            $destFile = $destDir . DS . strtolower($file['fileName']);
            if (!copy($file['sourcePath'], $destFile)) {
                $errors[] = format(
                    __d('admin', "Failed to copy file “{file}” to directory “{dir}”."),
                    array('file' => $file['fileName'], 'dir' => $destDir)
                );
                continue;
            }

            $ok = $this->assignAudioTo($file['sentenceId'], $author, false);
            if (!$ok) {
                $errors[] = format(
                    __d('admin', "Unable to assign audio to “{author}” for sentence {sentenceId} inside the database. Make sure it's a valid username."),
                    array('sentenceId' => $file['sentenceId'], 'author' => $author)
                );
                unlink($destFile); // cleaning up, no need to warn on error
                continue;
            }

            if (!unlink($file['sourcePath'])) {
                $errors[] = format(
                    __d('admin', "File “{file}” was successfully imported but could not be removed from the import directory."),
                    array('file' => $file['fileName'])
                );
            }

            if (!isset($filesImported[$file['lang']]))
                $filesImported[$file['lang']] = 0;
            $filesImported[$file['lang']]++;
            $filesImported['total']++;
        }
        
        return $filesImported;
    }

    /**
     * Update audio count.
     *
     * @return void
     */
    public function updateCount()
    {
        $query = "
            UPDATE `languages` l,
                (SELECT count(distinct sentence_id) as count, lang
                FROM audios JOIN sentences ON audios.sentence_id = sentences.id
                GROUP BY lang
                ) as s
            SET audio = s.count
            WHERE l.code = s.lang;
        ";
        $this->query($query);
    }
}
