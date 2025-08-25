START TRANSACTION;

ALTER TABLE `tarallo_cards`
	ADD `workspace_id` INT NOT NULL DEFAULT 1;

CREATE TABLE `tarallo_workspaces`
(
    `id`        INT AUTO_INCREMENT PRIMARY KEY,
    `name`      VARCHAR(64) NOT NULL,
    `slug`      VARCHAR(64) NOT NULL,
    `slug_hash` CHAR(32) NOT NULL,
    `logo_id`   INT NULL,
    `is_public` TINYINT(1) DEFAULT 0 NOT NULL
);

CREATE UNIQUE INDEX `workspace_slug_hash`
    ON `tarallo_workspaces` (`slug_hash`);

CREATE TABLE `tarallo_workspace_permissions`
(
    `id`        INT AUTO_INCREMENT,
    `user_id`   INT NOT NULL,
    `workspace_id`  INT NOT NULL,
    `user_type` INT NOT NULL,
    CONSTRAINT id UNIQUE (id)
);

CREATE INDEX `user_and_workspace`
    ON `tarallo_workspace_permissions` (`user_id`, `workspace_id`);

CREATE TABLE `tarallo_logos`
(
    `ìd`        INT AUTO_INCREMENT PRIMARY KEY,
    `name`      VARCHAR(64) NOT NULL,
    `filename`  VARCHAR(64) NOT NULL
);

UPDATE `tarallo_settings`
SET `value` = '6'
WHERE `tarallo_settings`.`name` = 'db_version';

ALTER TABLE `tarallo_boards`
    ADD `workspace_id` INT NOT NULL DEFAULT 0;

CREATE INDEX `workspace`
    ON `tarallo_boards` (`workspace_id`);

COMMIT;