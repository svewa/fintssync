-- Per customer skonto allowance and booking mode
CREATE TABLE llx_fintssync_customer (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_soc integer NOT NULL,
	skonto_percent double(8,4) DEFAULT 0 NOT NULL,
	skonto_days integer DEFAULT 0 NOT NULL,		-- 0 = no deadline
	mode varchar(10) DEFAULT 'review' NOT NULL,	-- auto or review
	tms timestamp
) ENGINE=innodb;
