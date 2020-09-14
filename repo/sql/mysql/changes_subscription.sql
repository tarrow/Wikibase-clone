-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/Wikibase/repo/sql/abstract/changes_subscription.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/wb_changes_subscription (
  cs_row_id BIGINT AUTO_INCREMENT NOT NULL,
  cs_entity_id VARBINARY(255) NOT NULL,
  cs_subscriber_id VARBINARY(255) NOT NULL,
  UNIQUE INDEX cs_entity_id (cs_entity_id, cs_subscriber_id),
  INDEX cs_subscriber_id (cs_subscriber_id),
  PRIMARY KEY(cs_row_id)
) /*$wgDBTableOptions*/;
