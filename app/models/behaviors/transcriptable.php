<?php
/**
 * Tatoeba Project, free collaborative creation of languages corpuses project
 * Copyright (C) 2015  Gilles Bedel
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Model behavior for transcriptions/transliterations.
 * Only attached to the Sentence model.
 */
class TranscriptableBehavior extends ModelBehavior
{
    public function afterSave(&$model, $created) {
        $this->updateTranscription($model, $created);
    }

    private function updateTranscription($model, $created) {
        if ($created) {
            $sentence = $model->data[$model->alias];
            if (!isset($sentence['id']))
                $sentence['id'] = $model->getLastInsertID();
            $model->Transcription->generateAndSaveAllTranscriptionsFor($sentence);
        }
    }

    public function afterFind(&$model, $results, $primary) {
        foreach ($results as &$result) {
            if (isset($result['Transcription'])) {
                $result['Transcription'] =
                    $model->Transcription->addGeneratedTranscriptions(
                        $result['Transcription'], $result[$model->alias]
                    );
            }
        }
        return $results;
    }
}
