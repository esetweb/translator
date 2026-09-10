CREATE TABLE tx_esettranslator_domain_model_job (
	job_identifier varchar(64) DEFAULT '' NOT NULL,
	title varchar(255) DEFAULT '' NOT NULL,
	page_uid int(11) unsigned DEFAULT '0' NOT NULL,
	depth int(11) unsigned DEFAULT '0' NOT NULL,
	source_site varchar(100) DEFAULT '' NOT NULL,
	source_language_id int(11) DEFAULT '0' NOT NULL,
	target_site varchar(100) DEFAULT '' NOT NULL,
	target_language_id int(11) DEFAULT '0' NOT NULL,
	mode varchar(20) DEFAULT 'manual' NOT NULL,
	provider varchar(50) DEFAULT '' NOT NULL,
	format varchar(50) DEFAULT '' NOT NULL,
	status varchar(20) DEFAULT 'new' NOT NULL,
	unit_count int(11) unsigned DEFAULT '0' NOT NULL,
	translated_count int(11) unsigned DEFAULT '0' NOT NULL,
	imported_count int(11) unsigned DEFAULT '0' NOT NULL,
	error_message text,
	export_file varchar(255) DEFAULT '' NOT NULL,
	import_file varchar(255) DEFAULT '' NOT NULL,
	backend_user_id int(11) unsigned DEFAULT '0' NOT NULL,
	started_at int(11) unsigned DEFAULT '0' NOT NULL,
	finished_at int(11) unsigned DEFAULT '0' NOT NULL,
	items int(11) unsigned DEFAULT '0' NOT NULL,

	KEY job_identifier (job_identifier),
	KEY status_mode (status,mode),
	KEY page (page_uid)
);

CREATE TABLE tx_esettranslator_domain_model_jobitem (
	job int(11) unsigned DEFAULT '0' NOT NULL,
	table_name varchar(100) DEFAULT '' NOT NULL,
	record_uid int(11) unsigned DEFAULT '0' NOT NULL,
	field_name varchar(100) DEFAULT '' NOT NULL,
	record_page_uid int(11) unsigned DEFAULT '0' NOT NULL,
	target_uid int(11) unsigned DEFAULT '0' NOT NULL,
	source_text mediumtext,
	target_text mediumtext,
	source_hash varchar(40) DEFAULT '' NOT NULL,
	html tinyint(1) unsigned DEFAULT '0' NOT NULL,
	status varchar(20) DEFAULT 'pending' NOT NULL,
	error_message text,

	KEY job (job),
	KEY record (table_name,record_uid)
);
