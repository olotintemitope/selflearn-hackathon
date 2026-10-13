# Proposed data model
Confirm names and relationships against the Laravel application before migrations.

| Entity | Essential fields |
| --- | --- |
| learning_spaces | id, user_id, title, goal, preferred_language |
| materials | id, learning_space_id, title, private_source_path, type, status, extraction_version, metadata |
| material_chunks | id, material_id, extraction_version, content, page, section, locator |
| past_paper_questions | uuid, material_id, source chunk, extraction version, question number, original wording, detected format, private expected response, review status |
| past_paper_practice_runs | uuid, anchor material_id, ordered selected_material_ids snapshot, user_id, ordered question UUID snapshot, started_at, learner-selected duration_minutes, expires_at, submitted_at |
| past_paper_attempts | uuid, optional practice_run_id, past_paper_question_id, user_id, submitted response, outcome, marking basis, submitted_at |
| concepts | id, learning_space_id, name, description, difficulty |
| concept_sources | concept_id, chunk_id |
| concept_dependencies | concept_id, prerequisite_concept_id |
| learning_paths | id, learning_space_id, goal, version |
| learning_path_items | id, learning_path_id, concept_id, position |
| tutor_messages | id, learning_space_id, role, content, citations, prompt_version |
| assessments | id, learning_space_id, version, status |
| assessment_questions | id, assessment_id, concept_id, prompt, options, private_correct_answer, source_refs |
| assessment_attempts | id, user_id, assessment_id, client_attempt_uuid, submitted_at |
| assessment_answers | attempt_id, question_id, selected_option, correct |
| mastery_evidence | id, user_id, concept_id, attempt_id, outcome, assessed_at |
| mastery | user_id, concept_id, observed_accuracy, evidence_count, last_assessed_at |

Unique constraints: concept dependency pair; user + client_attempt_uuid; attempt + question; user + concept summary. Unknown accuracy is nullable. Keep answer keys server-only. Chunk references must resolve to the same authorized space.

Current POC path progress is derived from submitted multiple-choice answers for concepts in the active path. A concept counts as practised after at least one submitted answer; completing practice is not mastery. The `mastery_evidence` and `mastery` tables above are proposed future structures, not currently required tables. Badges, points, and streaks are not specified by the current product scope.
