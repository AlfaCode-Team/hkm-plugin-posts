<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Posts — `posts`, `post_tags`, `post_categories`, `post_category_links`,
 * `post_revisions`, `post_slug_redirects`.
 *
 * Shipped IDENTICALLY in database/migrations (central) and
 * database/tenant-template, so the plugin works on a project with or without
 * Tenancy: the rows live on whichever connection the request is routed to.
 * Keep the two copies byte-identical — MigrationTest fails otherwise.
 *
 * One table for blog posts, articles and press releases — `kind` tells them
 * apart, and is a varchar rather than an enum so a new kind needs no
 * migration. `status` is NEVER stored: it follows from `deleted_at`,
 * `is_published` and `published_at`.
 *
 * Every cross-table reference holds a PUBLIC id (ULID), never an internal
 * auto-increment, so no write needs `lastInsertId()` (which on PostgreSQL
 * needs a sequence name). There are no foreign keys: PostRepository removes a
 * post's dependents with it, inside one transaction, and FKs across these
 * tables would make the plugin's own uninstall order-sensitive.
 *
 * NOTE: LetMigrate's MySQL grammar currently ignores a COLUMN-level
 * charset/collation, so on MySQL the ULID columns take the table's
 * (case-insensitive) collation. All of them share it, so joins still use their
 * indexes, and PostService normalises ids and slugs before they reach SQL so
 * lookups behave the same on every driver.
 *
 * `body_html` + `toc` hold the body rendered to SAFE HTML at save time, so a
 * read never renders.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('posts')) {
            $schema->create('posts', static function ($t) {
                $t->id();
                $t->ulid('public_id')->charset('ascii')->collation('ascii_bin')
                    ->comment('public identifier; never expose the internal id');

                $t->string('kind', 20)->default('blog')->comment('blog | article | press');
                $t->string('locale', 15)->default('')->comment('BCP 47 tag; empty = the site default');
                $t->string('slug', 160)->comment('unique per (kind, locale)');
                $t->ulid('translation_group')->charset('ascii')->collation('ascii_bin')->nullable()
                    ->comment('posts sharing this value are translations of one another');

                $t->string('title', 200);
                $t->string('subtitle', 200)->nullable();
                $t->string('excerpt', 500)->nullable();
                $t->string('summary', 300)->default('')->comment('derived at save: excerpt, or the body opening');
                $t->unsignedSmallInteger('reading_minutes')->default(1)->comment('derived at save');
                $t->mediumText('body')->nullable()->comment('as written, in body_format');
                $t->string('body_format', 10)->default('markdown')->comment('markdown | html | text');
                $t->mediumText('body_html')->nullable()->comment('body rendered to SAFE html at save time');
                $t->text('toc')->nullable()->comment('JSON list of {level, id, text} headings');

                $t->string('cover_image_url', 500)->nullable()->comment('absolute http(s) or root-relative path');
                $t->string('cover_image_alt', 200)->nullable();

                $t->string('author_id', 64)->nullable()->comment('soft reference to a user id; no FK');
                $t->string('author_name', 120)->nullable()->comment('byline as printed');

                $t->string('dateline', 120)->nullable()->comment('press: e.g. "KAMPALA, Uganda"');
                $t->string('contact', 500)->nullable()->comment('press: media contact');
                $t->string('source_name', 120)->nullable()->comment('coverage: the publication');
                $t->string('source_url', 500)->nullable()->comment('coverage: absolute http(s) URL');

                $t->string('meta_title', 160)->nullable();
                $t->string('meta_description', 320)->nullable();
                $t->string('canonical_url', 500)->nullable()->comment('for syndicated content: where the original lives');
                $t->boolean('noindex')->default(false)->comment('ask search engines not to index');

                $t->boolean('is_featured')->default(false);
                $t->boolean('comments_enabled')->default(true);
                $t->boolean('is_published')->default(false);
                $t->dateTime('published_at')->nullable()->comment('UTC; a future value schedules the post');
                $t->dateTime('announced_at')->nullable()->comment('UTC; when post.published went out for this publication');
                $t->dateTime('deleted_at')->nullable()->comment('UTC; set = in the trash');

                $t->unsignedInteger('view_count')->default(0);
                // Bumped by every save, which is conditional on it: two
                // editors saving the same post get a conflict, not a lost update.
                $t->unsignedInteger('version')->default(1);

                $t->string('created_by', 64)->nullable()->comment('soft reference to a user id; no FK');
                $t->string('updated_by', 64)->nullable();

                $t->timestamps();

                $t->unique(['public_id'], 'uniq_post_public_id');
                $t->unique(['kind', 'locale', 'slug'], 'uniq_post_kind_locale_slug');
                $t->index(['kind', 'is_published', 'published_at'], 'idx_post_kind_visibility');
                $t->index(['is_published', 'published_at'], 'idx_post_visibility');
                $t->index(['translation_group'], 'idx_post_translation_group');
                // The every-minute `posts:publish-due` scan looks only at the
                // few posts not yet announced. Without this it walks every
                // published post (100,000 posts: 10 ms down to 0.13 ms).
                $t->index(['announced_at', 'published_at'], 'idx_post_announcement');
                // Author archives: one author's public posts, newest first.
                $t->index(['author_id', 'published_at'], 'idx_post_author');

                $t->engine('InnoDB');
                $t->charset('utf8mb4');
                $t->collation('utf8mb4_0900_ai_ci');
                $t->rowFormat('DYNAMIC');
            });
        }

        if (!$schema->hasTable('post_tags')) {
            $schema->create('post_tags', static function ($t) {
                $t->id();
                $t->ulid('post_id')->charset('ascii')->collation('ascii_bin')->comment('posts.public_id');
                $t->string('tag', 40)->comment('normalised: lower-case, hyphenated');

                $t->unique(['post_id', 'tag'], 'uniq_post_tag');
                $t->index(['tag'], 'idx_post_tag_tag');

                $t->engine('InnoDB');
                $t->charset('utf8mb4');
                $t->collation('utf8mb4_0900_ai_ci');
                $t->rowFormat('DYNAMIC');
            });
        }

        if (!$schema->hasTable('post_categories')) {
            $schema->create('post_categories', static function ($t) {
                $t->id();
                $t->ulid('public_id')->charset('ascii')->collation('ascii_bin');
                $t->ulid('parent_id')->charset('ascii')->collation('ascii_bin')->nullable()
                    ->comment('post_categories.public_id; null = top level');
                $t->string('slug', 160);
                $t->string('name', 80);
                $t->string('description', 500)->nullable();
                $t->integer('position')->default(0)->comment('sort order among siblings');

                $t->timestamps();

                $t->unique(['public_id'], 'uniq_post_category_public_id');
                $t->unique(['slug'], 'uniq_post_category_slug');
                $t->index(['parent_id'], 'idx_post_category_parent');

                $t->engine('InnoDB');
                $t->charset('utf8mb4');
                $t->collation('utf8mb4_0900_ai_ci');
                $t->rowFormat('DYNAMIC');
            });
        }

        if (!$schema->hasTable('post_category_links')) {
            $schema->create('post_category_links', static function ($t) {
                $t->id();
                $t->ulid('post_id')->charset('ascii')->collation('ascii_bin')->comment('posts.public_id');
                $t->ulid('category_id')->charset('ascii')->collation('ascii_bin')->comment('post_categories.public_id');

                $t->unique(['post_id', 'category_id'], 'uniq_post_category_link');
                $t->index(['category_id'], 'idx_post_category_link_category');

                $t->engine('InnoDB');
                $t->charset('utf8mb4');
                $t->collation('utf8mb4_0900_ai_ci');
                $t->rowFormat('DYNAMIC');
            });
        }

        if (!$schema->hasTable('post_revisions')) {
            $schema->create('post_revisions', static function ($t) {
                $t->id();
                $t->ulid('post_id')->charset('ascii')->collation('ascii_bin')->comment('posts.public_id');
                $t->unsignedInteger('number')->comment('1, 2, 3 … per post; never reused');
                $t->mediumText('snapshot')->comment('JSON: the create/update input that reproduces this revision');
                $t->string('note', 200)->nullable();
                $t->string('created_by', 64)->nullable();
                $t->dateTime('created_at');

                $t->unique(['post_id', 'number'], 'uniq_post_revision_number');

                $t->engine('InnoDB');
                $t->charset('utf8mb4');
                $t->collation('utf8mb4_0900_ai_ci');
                $t->rowFormat('DYNAMIC');
            });
        }

        if (!$schema->hasTable('post_slug_redirects')) {
            $schema->create('post_slug_redirects', static function ($t) {
                $t->id();
                $t->string('kind', 20);
                $t->string('locale', 15)->default('');
                $t->string('slug', 160)->comment('a slug this post USED to have');
                $t->ulid('post_id')->charset('ascii')->collation('ascii_bin')->comment('posts.public_id');
                $t->dateTime('created_at');

                $t->unique(['kind', 'locale', 'slug'], 'uniq_post_slug_redirect');
                $t->index(['post_id'], 'idx_post_slug_redirect_post');

                $t->engine('InnoDB');
                $t->charset('utf8mb4');
                $t->collation('utf8mb4_0900_ai_ci');
                $t->rowFormat('DYNAMIC');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('post_slug_redirects');
        $schema->dropIfExists('post_revisions');
        $schema->dropIfExists('post_category_links');
        $schema->dropIfExists('post_categories');
        $schema->dropIfExists('post_tags');
        $schema->dropIfExists('posts');
    }
};
