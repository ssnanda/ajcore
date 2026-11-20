# AJ Core: agent notes

## ID ownership (wp_posts and synced tables)

- **Each site owns an ID range, not each record type.** Live owns post IDs at or above
  3,000,000,000. Its wp_posts counter is raised by hand; code never does this. DDEV stays
  below that.
- **Synchy enforces this:** option `synchy_post_id_floor`, rules E6 and E7.
- **MySQL AUTO_INCREMENT always sits above MAX(ID).** One row inserted with a high explicit
  ID drags every later post, revision and upload on that site into the high range. That is
  why reserving an ID range for a record type cannot work.
- **Code that creates posts uses the normal counter.** Never pass `import_id`, an explicit
  `ID`, or any other explicit high ID for wp_posts or any synced table.
- **Never run `ALTER TABLE … AUTO_INCREMENT` from code.**
- **Customer files** are identified only by `aj_portal_files`, `aj_storage_objects` and the
  `_ajcore_customer_file` meta, never by their ID. Do not move or renumber existing
  customer files.
- **Check post-ID changes against Synchy rules E1–E7** before shipping any change that
  touches post IDs.
