ALTER TABLE posts
  ADD COLUMN deleted_by BINARY(16) NULL AFTER deleted_at,
  ADD KEY idx_posts_deleted_by (deleted_by),
  ADD CONSTRAINT fk_posts_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE RESTRICT,
  ADD CONSTRAINT chk_posts_deleted_by CHECK (deleted_by IS NULL OR status = 'deleted');

ALTER TABLE comments
  ADD COLUMN deleted_by BINARY(16) NULL AFTER deleted_at,
  ADD KEY idx_comments_deleted_by (deleted_by),
  ADD CONSTRAINT fk_comments_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE RESTRICT,
  ADD CONSTRAINT chk_comments_deleted_by CHECK (deleted_by IS NULL OR status = 'deleted');
