<?php
/*

 $Id$

*/
/**
 * Default sitemap builder
 *
 * @author Arne Brachhold
 * @package sitemap
 * @since 4.0
 */
class GoogleSitemapGeneratorStandardBuilder {

	public function __construct() {
		add_action("sm_build_index", array($this, "Index"), 10, 1);
		add_action("sm_build_content", array($this, "Content"), 10, 3);
	}

	/**
	 * @param $gsg GoogleSitemapGenerator
	 * @param $type String
	 * @param $params array
	 */
	public function Content($gsg, $type, $params) {

		switch($type) {
			case "pt":
				$this->BuildPosts($gsg, $type, $params);
				break;
			case "archives":
				$this->BuildArchives($gsg);
				break;
			case "authors":
				$this->BuildAuthors($gsg);
				break;
			case "tax":
				$this->BuildTaxonomies($gsg, $params);
				break;
			case "externals":
				$this->BuildExternals($gsg);
				break;
			case "misc":
				$this->BuildMisc($gsg);
				break;
		}
	}

	/**
	 * Adds a condition to the query to filter out password protected posts
	 * @param $where The where statement
	 * @return String Changed where statement
	 */
	public function FilterPassword($where) {
		global $wpdb;
		$where .= "AND ($wpdb->posts.post_password = '') ";
		return $where;
	}

	/**
	 * Adds the list of required fields to the query so no big fields like post_content will be selected
	 * @param $fields The current fields
	 * @return String Changed fields statement
	 */
	public function FilterFields($fields) {
		global $wpdb;

		$newFields = array(
			$wpdb->posts . ".ID",
			$wpdb->posts . ".post_author",
			$wpdb->posts . ".post_date",
			$wpdb->posts . ".post_date_gmt",
			$wpdb->posts . ".post_content",
			$wpdb->posts . ".post_title",
			$wpdb->posts . ".post_excerpt",
			$wpdb->posts . ".post_status",
			$wpdb->posts . ".post_name",
			$wpdb->posts . ".post_modified",
			$wpdb->posts . ".post_modified_gmt",
			$wpdb->posts . ".post_content_filtered",
			$wpdb->posts . ".post_parent",
			$wpdb->posts . ".guid",
			$wpdb->posts . ".post_type", "post_mime_type",
			$wpdb->posts . ".comment_count"
		);

		$fields = implode(", ", $newFields);
		return $fields;
	}


	/**
	 * @param $gsg GoogleSitemapGenerator
	 * @param $params String
	 */
	public function BuildPosts($gsg, $type, $params) {

		if(!$pts = strpos($params, "-")) return;

		$postType = substr($params, 0, $pts);

		if(!in_array($postType, $gsg->GetActivePostTypes())) return;

		$params = substr($params, $pts + 1);

		global $wp_version;

		if(preg_match('/^([0-9]{4})\-([0-9]{2})$/', $params, $matches)) {
			$year = $matches[1];
			$month = $matches[2];

			//All comments as an asso. Array (postID=>commentCount)
			$comments = ($gsg->GetOption("b_prio_provider") != "" ? $gsg->GetComments() : array());

			//Full number of comments
			$commentCount = (count($comments) > 0 ? $gsg->GetCommentCount($comments) : 0);

			$qp = $this->BuildPostQuery($gsg, $postType);

			$qp['year'] = $year;
			$qp['monthnum'] = $month;

			//Don't retrieve and update meta values and taxonomy terms if they are not used in the permalink
			$struct = get_option('permalink_structure');
			if(strpos($struct, "%category%") === false && strpos($struct, "%tag%") == false) {
				$qp['update_post_term_cache'] = false;
			}

			$qp['update_post_meta_cache'] = false;

			//Add filter to remove password protected posts
			add_filter('posts_search', array($this, 'FilterPassword'), 10, 1);

			//Add filter to filter the fields
			add_filter('posts_fields', array($this, 'FilterFields'), 10, 1);

			$posts = get_posts($qp);

			//Remove the filter again
			remove_filter("posts_where", array($this, 'FilterPassword'), 10, 1);
			remove_filter("posts_fields", array($this, 'FilterFields'), 10, 1);

			if($postCount = count($posts) > 0) {

				$prioProvider = NULL;

				if($gsg->GetOption("b_prio_provider") != '') {
					$providerClass = $gsg->GetOption('b_prio_provider');
					$prioProvider = new $providerClass($commentCount, $postCount);
				}

				//Default priorities
				$default_prio_posts = $gsg->GetOption('pr_posts');
				$default_prio_pages = $gsg->GetOption('pr_pages');

				//Change frequencies
				$cf_pages = $gsg->GetOption('cf_pages');
				$cf_posts = $gsg->GetOption('cf_posts');

				//Minimum priority
				$minPrio = $gsg->GetOption('pr_posts_min');

				//Page as home handling
				$homePid = 0;
				$home = get_bloginfo('url');
				if('page' == get_option('show_on_front') && get_option('page_on_front')) {
					$pageOnFront = get_option('page_on_front');
					$p = get_post($pageOnFront);
					if($p) $homePid = $p->ID;
				}

				foreach($posts AS $post) {

					$permalink = get_permalink($post->ID);

					//Exclude the home page and placeholder items by some plugins...
					if(!empty($permalink) && $permalink != $home && $post->ID != $homePid && $permalink != "#") {

						//Default Priority if auto calc is disabled
						$prio = ($postType == 'page' ? $default_prio_pages : $default_prio_posts);

						//If priority calc. is enabled, calculate (but only for posts, not pages)!
						if($prioProvider !== null && $postType == 'post') {
							//Comment count for this post
							$cmtcnt = (isset($comments[$post->ID]) ? $comments[$post->ID] : 0);
							$prio = $prioProvider->GetPostPriority($post->ID, $cmtcnt, $post);
						}

						if($postType == 'post' && $minPrio > 0 && $prio < $minPrio) $prio = $minPrio;

						$gsg->AddUrl($permalink, $gsg->GetTimestampFromMySql(($post->post_modified_gmt && $post->post_modified_gmt != '0000-00-00 00:00:00'
								? $post->post_modified_gmt
								: $post->post_date_gmt)), ($postType == 'page' ? $cf_pages
								: $cf_posts), $prio, $post->ID);

					}
				}
			}
		}
	}

	/**
	 * @param $gsg GoogleSitemapGenerator
	 */
	public function BuildArchives($gsg) {
		global $wpdb, $wp_version;
		$now = current_time('mysql');

		$arcresults = $wpdb->get_results("
			SELECT DISTINCT
				YEAR(post_date_gmt) AS `year`,
				MONTH(post_date_gmt) AS `month`,
				MAX(post_date_gmt) AS last_mod,
				count(ID) AS posts
			FROM
				$wpdb->posts
			WHERE
				post_date < '$now'
				AND post_status = 'publish'
				AND post_type = 'post'
			GROUP BY
				YEAR(post_date_gmt),
				MONTH(post_date_gmt)
			ORDER BY
				post_date_gmt DESC
		");

		if($arcresults) {
			foreach($arcresults as $arcresult) {

				$url = get_month_link($arcresult->year, $arcresult->month);
				$changeFreq = "";

				//Archive is the current one
				if($arcresult->month == date("n") && $arcresult->year == date("Y")) {
					$changeFreq = $gsg->GetOption("cf_arch_curr");
				} else { // Archive is older
					$changeFreq = $gsg->GetOption("cf_arch_old");
				}

				$gsg->AddUrl($url, $gsg->GetTimestampFromMySql($arcresult->last_mod), $changeFreq, $gsg->GetOption("pr_arch"));
			}
		}
	}

	/**
	 * @param $gsg GoogleSitemapGenerator
	 */
	public function BuildMisc($gsg) {

		if($gsg->GetOption("in_home")) {
			$home = get_bloginfo('url');
			$homePid = 0;
			//Add the home page (WITH a slash!)
			if($gsg->GetOption("in_home")) {
				if('page' == get_option('show_on_front') && get_option('page_on_front')) {
					$pageOnFront = get_option('page_on_front');
					$p = get_page($pageOnFront);
					if($p) {
						$homePid = $p->ID;
						$gsg->AddUrl(trailingslashit($home), $gsg->GetTimestampFromMySql(($p->post_modified_gmt && $p->post_modified_gmt != '0000-00-00 00:00:00'
								? $p->post_modified_gmt
								: $p->post_date_gmt)), $gsg->GetOption("cf_home"), $gsg->GetOption("pr_home"));
					}
				} else {
					$lm = get_lastpostmodified('GMT');
					$gsg->AddUrl(trailingslashit($home), ($lm ? $gsg->GetTimestampFromMySql($lm)
							: time()), $gsg->GetOption("cf_home"), $gsg->GetOption("pr_home"));
				}
			}
		}

		if($gsg->IsXslEnabled() && $gsg->GetOption("b_html") === true) {
			$lm = get_lastpostmodified('GMT');
			$gsg->AddUrl($gsg->GetXmlUrl("", "", array("html" => true)), ($lm ? $gsg->GetTimestampFromMySql($lm)
					: time()));
		}

		do_action('sm_buildmap');
	}

	/**
	 * @param $gsg GoogleSitemapGenerator
	 */
	public function BuildAuthors($gsg) {
		global $wpdb, $wp_version;

		//Unfortunately there is no API function to get all authors, so we have to do it the dirty way...
		//We retrieve only users with published and not password protected enabled post types

		$enabledPostTypes = $gsg->GetActivePostTypes();

		//Ensure we count at least the posts...
		if(count($enabledPostTypes) == 0) $enabledPostTypes[] = "post";

		$sql = "SELECT DISTINCT
					u.ID,
					u.user_nicename,
					MAX(p.post_modified_gmt) AS last_post
				FROM
					{$wpdb->users} u,
					{$wpdb->posts} p
				WHERE
					p.post_author = u.ID
					AND p.post_status = 'publish'
					AND p.post_type IN('" . implode("','", array_map(array($wpdb, 'escape'), $enabledPostTypes)) . "')
					AND p.post_password = ''
				GROUP BY
					u.ID,
					u.user_nicename";

		$authors = $wpdb->get_results($sql);

		if($authors && is_array($authors)) {
			foreach($authors as $author) {
				$url = get_author_posts_url($author->ID, $author->user_nicename);
				$gsg->AddUrl($url, $gsg->GetTimestampFromMySql($author->last_post), $gsg->GetOption("cf_auth"), $gsg->GetOption("pr_auth"));
			}
		}
	}


	public function FilterTermsQuery($selects, $args) {
		global $wpdb;
		$selects[] = "
		( /* ADDED BY XML SITEMAPS */
			SELECT
				UNIX_TIMESTAMP(MAX(p.post_date_gmt)) as _mod_date
			FROM
				{$wpdb->posts} p,
				{$wpdb->term_relationships} r
			WHERE
				p.ID = r.object_id
				AND p.post_status = 'publish'
				AND p.post_password = ''
				AND r.term_taxonomy_id = tt.term_taxonomy_id
		) as _mod_date";

		return $selects;
	}

	/**
	 * @param $gsg GoogleSitemapGenerator
	 */
	public function BuildTaxonomies($gsg, $taxonomy) {
		global $wpdb;

		$enabledTaxonomies = $this->GetEnabledTaxonomies($gsg);
		if(in_array($taxonomy, $enabledTaxonomies)) {

			$excludes = array();

			if($taxonomy == "category") {
				$exclCats = $gsg->GetOption("b_exclude_cats"); // Excluded cats
				if($exclCats) $excludes = $exclCats;
			}

			add_filter("get_terms_fields", array($this, "FilterTermsQuery"), 20, 2);
			$terms = get_terms($taxonomy, array("hide_empty" => true, "hierarchical" => false, "exclude" => $excludes));
			remove_filter("get_terms_fields", array($this, "FilterTermsQuery"), 20, 2);

			foreach($terms AS $term) {
				$gsg->AddUrl(get_term_link($term, $term->taxonomy), $term->_mod_date, $gsg->GetOption("cf_tags"), $gsg->GetOption("pr_tags"));
			}
		}
	}

	public function GetEnabledTaxonomies(GoogleSitemapGenerator $gsg) {

		$enabledTaxonomies = $gsg->GetOption("in_tax");
		if($gsg->GetOption("in_tags")) $enabledTaxonomies[] = "post_tag";
		if($gsg->GetOption("in_cats")) $enabledTaxonomies[] = "category";

		$taxList = array();
		foreach($enabledTaxonomies as $taxName) {
			$taxonomy = get_taxonomy($taxName);
			if($taxonomy && wp_count_terms($taxonomy->name, array('hide_empty' => true)) > 0) $taxList[] = $taxonomy->name;
		}
		return $taxList;
	}

	/**
	 * @param $gsg GoogleSitemapGenerator
	 */
	public function BuildExternals($gsg) {
		$pages = $gsg->GetPages();
		if($pages && is_array($pages) && count($pages) > 0) {
			foreach($pages AS $page) {
				$gsg->AddUrl($page->GetUrl(), $page->getLastMod(), $page->getChangeFreq(), $page->getPriority());
			}
		}
	}

	public function BuildPostQuery($gsg, $postType) {
		//Default Query Parameters
		$qp = array(
			'post_type' => $postType,
			'numberposts' => 0,
			'nopaging' => true,
			'suppress_filters' => false
		);


		$excludes = $gsg->GetExcludedPostIDs($gsg);

		if(count($excludes) > 0) {
			$qp["post__not_in"] = $excludes;
		}

		// Excluded category IDs
		$exclCats = $gsg->GetExcludedCategoryIDs($gsg);

		if(count($exclCats) > 0) {
			$qp["category__not_in"] = $exclCats;
		}

		return $qp;
	}

	/**
	 * @param $gsg GoogleSitemapGenerator
	 */
	public function Index($gsg) {
		/**
		 * @var $wpdb wpdb
		 */
		global $wpdb, $wp_version;


		$blogUpdate = strtotime(get_lastpostdate('blog'));

		$gsg->AddSitemap("misc", null, $blogUpdate);

		if($gsg->GetOption("in_arch")) $gsg->AddSitemap("archives", null, $blogUpdate);
		if($gsg->GetOption("in_auth")) $gsg->AddSitemap("authors", null, $blogUpdate);

		$taxonomies = $this->GetEnabledTaxonomies($gsg);
		foreach($taxonomies AS $tax) {
			$gsg->AddSitemap("tax", $tax, $blogUpdate);
		}

		$pages = $gsg->GetPages();
		if(count($pages) > 0) $gsg->AddSitemap("externals", null, $blogUpdate);

		$enabledPostTypes = $gsg->GetActivePostTypes();

		if(count($enabledPostTypes) > 0) {

			$excludedPostIDs = $gsg->GetExcludedPostIDs($gsg);
			$exPostSQL = "";
			if(count($excludedPostIDs) > 0) {
				$exPostSQL = "AND p.ID NOT IN (" . implode(",", $excludedPostIDs) . ")";
			}

			$excludedCategoryIDs = $gsg->GetExcludedCategoryIDs($gsg);
			$exCatSQL = "";
			if(count($excludedCategoryIDs) > 0) {
				$exCatSQL = "AND ( p.ID NOT IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN (" . implode(",", $excludedCategoryIDs) . ")))";
			}

			foreach($enabledPostTypes AS $postType) {
				$q = "
					SELECT
						YEAR(p.post_date) AS `year`,
						MONTH(p.post_date) AS `month`,
						COUNT(p.ID) AS `numposts`,
						MAX(p.post_date) as `last_mod`
					FROM
						{$wpdb->posts} p
					WHERE
						p.post_password = ''
						AND p.post_type = '" . $wpdb->escape($postType) . "'
						AND p.post_status = 'publish'
						$exPostSQL
						$exCatSQL
					GROUP BY
						YEAR(p.post_date),
						MONTH(p.post_date)
					ORDER BY
						p.post_date DESC";

				$posts = $wpdb->get_results($q);

				if($posts) {
					foreach($posts as $post) {
						$gsg->AddSitemap("pt", $postType . "-" . sprintf("%04d-%02d", $post->year, $post->month), $gsg->GetTimestampFromMySql($post->last_mod));
					}
				}
			}
		}
	}
}

if(defined("WPINC")) new GoogleSitemapGeneratorStandardBuilder();