ALTER TABLE llx_fintssync_customer ADD UNIQUE INDEX uk_fintssync_customer_soc (entity, fk_soc);
