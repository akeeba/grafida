-- Grafida schema update: persist the Responses API response-id chain on saved chats.
--
-- Copyright (c) 2026 Nicholas K. Dionysopoulos
-- GNU General Public License version 3, or later

-- previous_response_id lets a resumed chat pass `previous_response_id` instead of
-- re-uploading the whole transcript. last_response_at is the ISO-8601 UTC timestamp
-- of the last response the provider returned, sent verbatim by the client (NOT the
-- gmdate('Y-m-d H:i:s') format the other timestamp columns use — see AiChatRepository)
-- so the SPA can decide whether the chain is still fresh enough to resume.
ALTER TABLE ai_chats ADD COLUMN previous_response_id TEXT;
ALTER TABLE ai_chats ADD COLUMN last_response_at     TEXT;
