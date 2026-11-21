-- Idempotent seed. Bound by bin/migrate.php (:id, :author, :slug_check). Statements are separated by the marker line below.
INSERT INTO posts (id, author_id, title, slug, excerpt, body_md, status, published_at)
SELECT UUID_TO_BIN(:id), u.id, 'Welcome to Inkwell', 'welcome-to-inkwell',
       'A small blog about writing, built in plain PHP.',
       'Inkwell is a **simple blog**.\n\n- Read posts\n- Search them\n- Log in to comment and upvote\n\nHappy reading.',
       'published', UTC_TIMESTAMP()
  FROM users u
 WHERE u.email = :author
   AND NOT EXISTS (SELECT 1 FROM posts p WHERE p.slug = 'welcome-to-inkwell')
-- split
INSERT INTO post_vote_counts (post_id, upvotes)
SELECT p.id, 0 FROM posts p
 WHERE p.slug = 'welcome-to-inkwell'
   AND NOT EXISTS (SELECT 1 FROM post_vote_counts c WHERE c.post_id = p.id)
