CREATE TABLE IF NOT EXISTS users (
  id            BINARY(16) NOT NULL PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,
  name          VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          VARCHAR(10) NOT NULL DEFAULT 'user',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  CONSTRAINT chk_users_role CHECK (role IN ('admin','user')),
  CONSTRAINT chk_users_name CHECK (CHAR_LENGTH(name) BETWEEN 1 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
  id            BINARY(16) NOT NULL PRIMARY KEY,
  author_id     BINARY(16) NOT NULL,
  title         VARCHAR(200) NOT NULL,
  slug          VARCHAR(220) NOT NULL,
  excerpt       VARCHAR(300) NOT NULL DEFAULT '',
  body_md       MEDIUMTEXT NOT NULL,
  cover_image   VARCHAR(255) NULL,
  status        VARCHAR(10) NOT NULL DEFAULT 'draft',
  published_at  DATETIME NULL,
  deleted_at    DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_posts_slug (slug),
  KEY idx_posts_status_published (status, published_at),
  KEY idx_posts_author (author_id),
  FULLTEXT KEY ft_posts_search (title, body_md),
  CONSTRAINT fk_posts_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_posts_status CHECK (status IN ('draft','published','deleted')),
  CONSTRAINT chk_posts_published_at CHECK (status <> 'published' OR published_at IS NOT NULL),
  CONSTRAINT chk_posts_deleted_at CHECK ((status = 'deleted') = (deleted_at IS NOT NULL)),
  CONSTRAINT chk_posts_title CHECK (CHAR_LENGTH(title) BETWEEN 1 AND 200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
  id         BINARY(16) NOT NULL PRIMARY KEY,
  post_id    BINARY(16) NOT NULL,
  user_id    BINARY(16) NOT NULL,
  body       TEXT NOT NULL,
  status     VARCHAR(10) NOT NULL DEFAULT 'visible',
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_comments_post_status_created (post_id, status, created_at),
  KEY idx_comments_user (user_id),
  CONSTRAINT fk_comments_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_comments_status CHECK (status IN ('visible','deleted')),
  CONSTRAINT chk_comments_deleted_at CHECK ((status = 'deleted') = (deleted_at IS NOT NULL)),
  CONSTRAINT chk_comments_body CHECK (CHAR_LENGTH(body) BETWEEN 1 AND 5000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_votes (
  post_id    BINARY(16) NOT NULL,
  user_id    BINARY(16) NOT NULL,
  status     VARCHAR(10) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id),
  KEY idx_votes_user (user_id),
  CONSTRAINT fk_votes_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_votes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_votes_status CHECK (status IN ('active','removed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_vote_counts (
  post_id BINARY(16) NOT NULL PRIMARY KEY,
  upvotes INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_vote_counts_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  throttle_key   BINARY(20) NOT NULL PRIMARY KEY,
  failures       INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until   DATETIME NULL,
  last_failed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
