-- SemanticSearch (MantisBT) schema script
-- Author: Gaston Burgardt
-- Target DB: mantisbt (MariaDB/MySQL)

CREATE TABLE IF NOT EXISTS mantisplugin_semsearch_issue (
  IssueId INT NOT NULL,
  CreatedAt INT NOT NULL DEFAULT 0,
  UpdatedAt INT NOT NULL DEFAULT 0,
  IndexedAt INT NULL DEFAULT NULL,
  Indexable TINYINT NOT NULL DEFAULT 0,
  Hash VARCHAR(64) NOT NULL DEFAULT '',
  Empty TINYINT NOT NULL DEFAULT 0,
  Indexed TINYINT NOT NULL DEFAULT 0,
  Action VARCHAR(16) NOT NULL DEFAULT 'Nothing',
  NivelDeRevision VARCHAR(24) NOT NULL DEFAULT 'NoRevisarNada',
  PRIMARY KEY (IssueId)
);

CREATE TABLE IF NOT EXISTS mantisplugin_semsearch_issuenote (
  NoteId INT NOT NULL,
  IssueId INT NOT NULL,
  CreatedAt INT NOT NULL DEFAULT 0,
  UpdatedAt INT NOT NULL DEFAULT 0,
  IndexedAt INT NULL DEFAULT NULL,
  Indexable TINYINT NOT NULL DEFAULT 0,
  Hash VARCHAR(64) NOT NULL DEFAULT '',
  Empty TINYINT NOT NULL DEFAULT 0,
  Indexed TINYINT NOT NULL DEFAULT 0,
  Action VARCHAR(16) NOT NULL DEFAULT 'Nothing',
  NivelDeRevision VARCHAR(24) NOT NULL DEFAULT 'NoRevisarNada',
  PRIMARY KEY (NoteId, IssueId)
);

CREATE TABLE IF NOT EXISTS mantisplugin_semsearch_issuenotefile (
  FileId INT NOT NULL,
  NoteId INT NOT NULL DEFAULT 0,
  IssueId INT NOT NULL,
  CreatedAt INT NOT NULL DEFAULT 0,
  UpdatedAt INT NOT NULL DEFAULT 0,
  IndexedAt INT NULL DEFAULT NULL,
  Indexable TINYINT NOT NULL DEFAULT 0,
  Hash VARCHAR(64) NOT NULL DEFAULT '',
  Empty TINYINT NOT NULL DEFAULT 0,
  Indexed TINYINT NOT NULL DEFAULT 0,
  Action VARCHAR(16) NOT NULL DEFAULT 'Nothing',
  NivelDeRevision VARCHAR(24) NOT NULL DEFAULT 'NoRevisarNada',
  PRIMARY KEY (FileId, NoteId, IssueId)
);

-- Cleanup legacy artifacts if present
DROP TABLE IF EXISTS mantisplugin_semsearch_attachment_index;
DROP TABLE IF EXISTS mantisplugin_semsearch_item_policy;
