<?php

// list of IP's that are allowed to hide and
// show synchronized documents.
$admin_ips = ["10.10.10.10"];

return [

    // name of the website.
    "app_name" => "CoCoS knowledgebase - Control any thing!",

    // base url of the website.
    "base_url" => "https://wiki.cocos.software",

    // setting to show all book in the doc tree (true) 
    // or only the selected book from the homepage.
    "doc_tree_show_all_books" => true,

    // public bookstack proxy DB. The database that is
    // use to synchronize data to from other bookstack
    // databases.
    "public_db" => [
        "host" => "127.0.0.1",
        "port" => 3306,
		'database' => '***',
		'username' => '***',
		'password' => '***',
        "charset" => "utf8mb4",
    ],

    // Original BookStack database.
    // Use a READ-ONLY database user.

    "admin_ips" => $admin_ips,
    "bookstack_sources" => [
        [
            "enabled" => true,
            "key" => "knowledgebase-cocos-software",
            "name" => "CoCoS Knowledgebase",
            "base_url" => "https://manuals.cocos.software",
            "schema" => "auto",

            "public_role_id" => [4],
            "public_permission_statuses" => [1],

            /*
             * inherited:
             *   page is synced if the configured role has permission on:
             *   page, chapter, book, or shelf
             *
             * direct_page_only:
             *   page is synced only if the configured role has permission directly
             *   on that page
             */
            "permission_mode" => "inherited",

            /*
             * Optional: these pages are created as hidden by default.
             * A later admin show/hide click must override this and survive sync.
             */
            "exclude_page_ids" => [],

            /*
             * Optional source-specific admins.
             */
            "admin_ips" => $admin_ips,

            "db" => [
                "host" => "127.0.0.1",
                "port" => 3306,
		'database' => '***',
		'username' => '***',
		'password' => '***',
                "charset" => "utf8mb4",
            ],
        ],

        [
            "enabled" => true,
            "key" => "knowledgebase-concera-software",
            "name" => "Concera Knowledgebase",
            "base_url" => "https://manuals.concera.com",
            "schema" => "auto",

            "public_role_id" => [31],
            "public_permission_statuses" => [1],

            /*
             * inherited:
             *   page is synced if the configured role has permission on:
             *   page, chapter, book, or shelf
             *
             * direct_page_only:
             *   page is synced only if the configured role has permission directly
             *   on that page
             */
            "permission_mode" => "direct_page_only",

            /*
             * Optional: these pages are created as hidden by default.
             * A later admin show/hide click must override this and survive sync.
             */
            "exclude_page_ids" => [1754, 1755, 1673, 1668],

            /*
             * Optional source-specific admins.
             */
            "admin_ips" => $admin_ips,

            "db" => [
                "host" => "10.10.89.128",
                "port" => 3306,
		'database' => '***',
		'username' => '***',
		'password' => '***',
                "charset" => "utf8mb4",
            ],
        ],
    ],

    // Configure this manually after checking the BookStack roles table.
    // This should be the role that represents public/guest readable content.
    //    'public_role_id' => 4,
    "cache_seconds" => 300,

    "bookstack_base_url" => "https://wiki.cocos.software",

    "generated_file_owner" => "concera",
    "generated_file_group" => "www-data",

    // configuration for access tokens.
    "access_gate_enabled" => true,
    "access_gate_code_ttl_minutes" => 10,
    "access_gate_session_days" => 7,
    "access_gate_mail_from" => "manual@cocos.software",
    "access_gate_mail_from_name" => "CoCoS Manual",
    "access_gate_allowed_domains" => [],
    "access_gate_remember_email_days" => 14,

    // smtp (smarthost) server settings to use for
    // sending mail. 
    "smtp" => [
        "host" => "***",
        "port" => 587,
        "encryption" => "tls", // tls, ssl, or none
	'username' => '***@***.***',
	'password' => '***',
        "timeout" => 60,
    ],
];
