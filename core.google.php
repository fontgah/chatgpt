<?php

// Main class to inherit wp_automatic
require_once 'core.php';
if (!function_exists('str_contains')) {
	function str_contains($haystack, $needle) {
		return $needle !== '' && mb_strpos($haystack, $needle) !== false;
	}
}
function isHTML($string){
	return ($string != strip_tags($string));
}
// Specific articles class
Class WpAutomaticGoogle extends wp_automatic{

	/*
	 * ---* article base get links for a keyword ---
	 */
	function article_base_getlinks($keyword, $camp) {

		$camp_opt = unserialize ( $camp->camp_options );

		if (stristr ( $camp->camp_general, 'a:' ))
			$camp->camp_general = base64_encode ( $camp->camp_general );
		$camp_general = unserialize ( base64_decode ( $camp->camp_general ) );

		// get associated page num from the keyword and camp id from wp_automatic_articles_keys
		$query = "select * from {$this->wp_prefix}automatic_articles_keys where keyword = '$keyword' and camp_id  = '$camp->camp_id'";
		$camp_key = $this->db->get_results ( $query );

		if (count ( $camp_key ) == 0){
			echo '<br>Keyword record not found';
			return false;
		}

		$camp_key = $camp_key [0];
		$foundUrls = array();

		$page = $camp_key->page_num;
		if (   $page == - 1) {
			//check if it is reactivated or still deactivated
			if($this->is_deactivated($camp->camp_id, $keyword)){
				$page = '0000';
			}else{
				//still deactivated
				return false;
			}
		}

		//Make sure start is 0,1,2 for bing
		if( ! stristr($page, '1994') ){
			$page = 0;

		}else{
			$page = str_replace('1994', '', $page);

		}

		if($page >9 ) $page = 9;

		$startIndex = 1 + 10 * $page;

		echo '<br>Trying to call EA for new links start from page:' . $page;
		//$keywordenc = urlencode ( 'site:ezinearticles.com '. trim($keyword). ' inurl:"id"'   );
		//$keywordenc = urlencode ( trim($keyword). ' inurl:"id"'   );
		$keywordenc =  trim($keyword) ;



		echo '<br>Using Google custom search to find new articles...';

		//verify Google custom search key existence
		$wp_automatic_search_key = get_option('wp_automatic_search_key','');

		if(trim($wp_automatic_search_key) == ''){
			echo '<br><span style="color:red" >Google custom search API key is required. Please visit the plugin settings page and add it inside EzineArticles settings box</span>';
			return false;
		}

		//Good we have some keys, verify usage limits

		//Now get one key out of many if applicable
		$wp_rankie_googlecustom_keys = explode(',', $wp_automatic_search_key);
		$wp_rankie_googlecustom_keys = array_filter($wp_rankie_googlecustom_keys);



		$now = time();

		$validWorkingKey = '';
		foreach ($wp_rankie_googlecustom_keys as $current_key){

			if(trim($current_key) != ''){

				//check if key is disabled or not
				$current_keyMd5 = md5($current_key);
				$disabledTill = get_option('wp_automatic_'.$current_keyMd5,'1463843434');

				if($disabledTill > $now){
					continue;
				}else{
					$validWorkingKey = $current_key;
					break;
				}

			}

		}

		if(trim($validWorkingKey) == ''){
			echo '<br><span style="color:red" >Custom search API keys reached its daily search requests limit, we will try again after one hour. each key gives us 100 daily search request.</b>';
			return false;
		}else{
			echo '<br>Using an added key:'.substr($validWorkingKey, 0,5).'.....';
		}

		$wp_rankie_googlecustom_id = 'c7149389caa494145';

		$wp_rankie_ezmlm_gl = 'google.com';


		$url ="https://www.googleapis.com/customsearch/v1?key=" . trim($validWorkingKey) . "&cx=" . trim($wp_rankie_googlecustom_id) . "&q=".$keywordenc.'&googlehost='.$wp_rankie_ezmlm_gl.'&start={stof}';
		//date limit
		if(in_array('OPT_ARTICLES_DATE' , $camp_opt )){

			$cg_articles_date_last_val = $camp_general['cg_articles_date_last_val'];
			$cg_articles_date_last = $camp_general['cg_articles_date_last'];


			if(is_numeric($cg_articles_date_last_val)){

				if($cg_articles_date_last == 'Months'){

					$url.= "&dateRestrict=m{$cg_articles_date_last_val}";

				}else{

					//years
					$url.= "&dateRestrict=y{$cg_articles_date_last_val}";

				}

			}

		}

		//echo '<br>Search URL:'.$url;

		//curl get
		$hbfug = 1;
		$foundLi = array();
		for ($xf = 1; $xf <= 2; $xf++) {
			$x='error';
			$urkk = str_replace("{stof}", $hbfug, trim($url));
			$dhhfj = wp_remote_get($urkk);
			echo "<br>".$urkk;
			$exec=wp_remote_retrieve_body($dhhfj);
			if (is_wp_error($dhhfj)) {
				$x= $dhhfj->get_error_message();
			}

			//validate a reply
			if(trim($exec) == ''){
				echo '<br>Empty reply from Google search API with possible cURL error '.$x;
				return false;
			}

			//validate json
			if( ! stristr($exec , '{') ){
				echo '<br>Not a json reply '.$exec;
				return false;
			}


			// good let's get results
			$jsonReply = json_decode($exec);

			if(isset($jsonReply->error)){

				$jsonErr = $jsonReply->error->errors[0];

				$errReason  = $jsonErr->reason;
				$errMessage = $jsonErr->message;

				$message = 'Api returned an error: '.$errReason.' '.$errMessage;
				echo '<br>'.$message;


				// disable limited keys
				if($errReason == 'dailyLimitExceeded'){
					update_option('wp_automatic_'.$current_keyMd5,$now + 60*60);
				}

				$return['message'] = $message;
				return $return;

			}
			if(isset($jsonReply->items)){
				$foundLinks = $jsonReply->items;
			}else{
				$foundLinks = array();
			}

			$foundLi = array_merge($foundLinks, $foundLi);
			$hbfug = $hbfug+10;
		}

		foreach ($foundLi as $foundLink){

			$finalUrl= $foundLink->link;
			$foundUrls[] = $finalUrl;

		}


		// No links? return if yes
		if(count($foundUrls) == 0 ){
			echo '<br> no matching results found for this keyword';
			$query = "update {$this->wp_prefix}automatic_articles_keys set page_num = '-1'  where keyword = '$keyword' and camp_id  = '$camp->camp_id'";
			$this->db->query ( $query );

			//deactivate permanently
			$this->deactivate_key($camp->camp_id, $keyword,0);
			return false;
		}else{
			// good lets update next page
			$page++;

			if($page > 9){

				$query = "update {$this->wp_prefix}automatic_articles_keys set page_num = '-1'  where keyword = '$keyword' and camp_id  = '$camp->camp_id'";
				$this->db->query ( $query );
				//deactivate for 60 minutes
				$this->deactivate_key($camp->camp_id, $keyword ,0);

			}else{

				$page= "1994$page";
				$query = "update {$this->wp_prefix}automatic_articles_keys set page_num = $page  where keyword = '$keyword' and camp_id  = '$camp->camp_id' ";
				$this->db->query ( $query );

			}


		}



		// Report links count
		echo '<br>Articles links got from EA:' . count ( $foundUrls );
		$this->log ( 'links found', count ( $foundUrls ) . ' New Links added from ezine articles to post articles from' );


		echo '<ol>';
		$i = 0;
		foreach ( $foundUrls as $link ) {

			$link =  urldecode($link);

			// verify id in link
			echo '<li>Link:'.($link);
			$link_url = $link;

			// verify uniqueness
			if( $this->is_execluded($camp->camp_id, $link_url) ){
				echo '<-- Execluded';
				continue;
			}

			if ( ! $this->is_duplicate($link_url) )  {

				$title = '';
				$cache = '';

				// cache link
				$urlEncoded = $link_url;
				$bingcache= '';

				$query = "insert into {$this->wp_prefix}automatic_articles_links (link,keyword,page_num,title,bing_cache) values('$link' ,'$keyword','$page','$title','$bingcache')";
				$this->db->query ( $query );


				$freshlinks = 1;


			} else {
				echo ' <- duplicated <a href="'.get_edit_post_link($this->duplicate_id).'">#'.$this->duplicate_id.'</a>';
			}

			echo '</li>';

				// incrementing i

			$i ++;
		} // foreach link

		echo '</ol>';

		// updating page num
		$page = $page + 1;
		$pageDisplayed = $page + 1;


		return;
	}

	/*
	 * ---* articlebase process camp ---
	 */
	function articlebase_get_post($camp) {

		$keywords = $camp->camp_keywords;
		$keywords = explode ( ",", $keywords );
		foreach ( $keywords as $keyword ) {

			$keyword = trim($keyword);

			if (trim ( $keyword ) != '') {


				//update last keyword
				update_post_meta($camp->camp_id, 'last_keyword', trim($keyword));

				// check if keyword exhausted to skip
				$query = "select * from {$this->wp_prefix}automatic_articles_keys where keyword = '$keyword' and camp_id='$camp->camp_id'";
				$key = $this->db->get_results ( $query );
				$key = $key [0];


				// process feed
				echo '<br><b>Getting article for Keyword:</b>' . $keyword;

				// get links to fetch and post on the blogs
				$query = "select * from {$this->wp_prefix}automatic_articles_links where keyword = '$keyword' ";
				$links = $this->db->get_results ( $query );

				// when no links available get some links
				if (count ( $links ) == 0) {

					//clean any old cache for this keyword
					$query_delete = "delete from {$this->wp_prefix}automatic_articles_links where  keyword = '$keyword'  ";
					$this->db->query ( $query_delete );

					$this->article_base_getlinks ( $keyword, $camp );

					// get links to fetch and post on the blogs
					$links = $this->db->get_results ( $query );
				}
				// if no links then return
				if (count ( $links ) != 0) {
					$confin = array(
						"cont" => array(),
						"url" => array(),
						"title" => array()
					);
					// محدودیت تعداد مطلب دریافتی از گوگل
					$tbld = 3;
					$ieee = 0;
					foreach ( $links as $link ) {
						if ($ieee < $tbld) {

    						// update the link status to 1
    						$query = "delete from {$this->wp_prefix}automatic_articles_links where id={$link->id}";
    						$this->db->query ( $query );
    
    						// processing page and getting content
    						$url = ($link->link) ;
    						$title = $link->title;
    						$cacheTxt = $link->bing_cache;
    
    						echo '<br>Processing Article :' . urldecode($url);
    
    						//duplicaate check
    						if($this->is_duplicate($url)){
    							echo ' <- duplicated <a href="'.get_edit_post_link($this->duplicate_id).'">#'.$this->duplicate_id.'</a>';
    							continue;
    						}
    
    						//Exclude دامنه ها
    						$ignored = file(plugin_dir_path(__FILE__)."/blocked/blocked.txt", FILE_IGNORE_NEW_LINES);;
    						$colkl = false;
    						foreach ($ignored as $fik) {
    							if ((!empty($fik)) && str_contains($url, $fik)) {
    								$colkl = true;
    								break;
    							}
    						}
    						if ($colkl) {
    							continue;
    						}
    						// Direct call
    						$dhhfj = wp_remote_get(trim($url));
    						$exec=wp_remote_retrieve_body($dhhfj);
    						if(isHTML($exec)){
    							echo '<br>Site returned the article successfully ';
    						}else{
    							echo '<br>Site not loaded successfully ';
    							continue;
    						}
    
    						$dom = new DomDocument();
    						$dom->loadHTML(mb_convert_encoding($exec, 'HTML-ENTITIES', 'UTF-8'));
    						$xpath = new DOMXpath($dom);
    						$contents = array(
    						    "//*[contains(@id, 'echo_detail')]",//خبرگذاری
    							"//*[contains(@class, 'entry-content')]",//پیش فرض وردپرس
    							"//*[contains(@class, 'post_content')]",//پیش فرض وردپرس
    							"//*[contains(@id, 'tab-description')]",//محصولات وکامرس
    							"//*[contains(@class, 'product-section')]",//محصولات وکامرس
    							"//*[contains(@class, 'term-description')]",//دسته وکامرس
    							"//*[contains(@itemprop, 'articleBody')]",//
    						);
    						//Exclude المنت ها
    						$ignorel = array(
    							"//form",
    							"//iframe",
    							"//script",
    							"//figure",
    							"//itemprop",
                                "//map",
                                "//*[contains(@class, 'addtoany_container')]",
                                "//*[contains(@class, 'aff-buttons')]",
                                "//*[contains(@class, 'alert')]",
                                "//*[contains(@class, 'alert-success')]",
                                "//*[contains(@class, 'article-detail')]",
                                "//*[contains(@class, 'article-social')]",
                                "//*[contains(@class, 'author_infobox')]",
                                "//*[contains(@class, 'blog-copyright')]",
                                "//*[contains(@class, 'blog-tags')]",
                                "//*[contains(@class, 'bottom-share')]",
                                "//*[contains(@class, 'bs-irp')]",
                                "//*[contains(@class, 'bs-shortcode-alert')]",
                                "//*[contains(@class, 'chetor-related-article')]",
                                "//*[contains(@class, 'code-block')]",
                                "//*[contains(@class, 'comment-heading')]",
                                "//*[contains(@class, 'comment-respond')]",
                                "//*[contains(@class, 'comments-area')]",
                                "//*[contains(@class, 'detail')]",
                                "//*[contains(@class, 'dkpagahwebbox')]",
                                "//*[contains(@class, 'ek-link')]",
                                "//*[contains(@class, 'entry-bottom')]",
                                "//*[contains(@class, 'entry-terms')]",
                                "//*[contains(@class, 'gallery')]",
                                "//*[contains(@class, 'gxe')]",
                                "//*[contains(@class, 'hidden')]",
                                "//*[contains(@class, 'in-single')]",
                                "//*[contains(@class, 'kksr-align-center')]",
                                "//*[contains(@class, 'kksr-valign-bottom')]",
                                "//*[contains(@class, 'kk-star-ratings')]",
                                "//*[contains(@class, 'lwptoc')]",
                                "//*[contains(@class, 'meta-single')]",
                                "//*[contains(@class, 'pager')]",
                                "//*[contains(@class, 'post-bottom-meta')]",
                                "//*[contains(@class, 'post-bottom-tags')]",
                                "//*[contains(@class, 'post-cat-wrap')]",
                                "//*[contains(@class, 'post-components')]",
                                "//*[contains(@class, 'post-content-share-mobile-contaner')]",
                                "//*[contains(@class, 'post-meta')]",
                                "//*[contains(@class, 'post-metafull')]",
                                "//*[contains(@class, 'post-related')]",
                                "//*[contains(@class, 'post-share')]",
                                "//*[contains(@class, 'post-switch')]",
                                "//*[contains(@class, 'post-tags')]",
                                "//*[contains(@class, 'post-tags-container')]",
                                "//*[contains(@class, 'post-tags-modern')]",
                                "//*[contains(@class, 'prevnext')]",
                                "//*[contains(@class, 'primary-heading')]",
                                "//*[contains(@class, 'quote')]",
                                "//*[contains(@class, 'rd-share-post')]",
                                "//*[contains(@class, 'related_template_footer')]",
                                "//*[contains(@class, 'related_template_footer_subject')]",
                                "//*[contains(@class, 'review')]",
                                "//*[contains(@class, 'review_wrap')]",
                                "//*[contains(@class, 'row bottom-article')]",
                                "//*[contains(@class, 'share-buttons')]",
                                "//*[contains(@class, 'share-buttons-bottom')]",
                                "//*[contains(@class, 'share-post')]",
                                "//*[contains(@class, 'sh-page-links')]",
                                "//*[contains(@class, 'side')]",
                                "//*[contains(@class, 'single-post-share')]",
                                "//*[contains(@class, 'single-related')]",
                                "//*[contains(@class, 'source')]",
                                "//*[contains(@class, 'source-link')]",
                                "//*[contains(@class, 'stream-item')]",
                                "//*[contains(@class, 'stream-item-inline-post')]",
                                "//*[contains(@class, 'stream-item-in-post')]",
                                "//*[contains(@class, 'tabs')]",
                                "//*[contains(@class, 'tag')]",
                                "//*[contains(@class, 'tags')]",
                                "//*[contains(@class, 'tags-cats')]",
                                "//*[contains(@class, 'td-block-row')]",
                                "//*[contains(@class, 'td-post-next-prev')]",
                                "//*[contains(@class, 'td-post-sharing-bottom')]",
                                "//*[contains(@class, 'td-post-source-tags')]",
                                "//*[contains(@class, 'wbc-shortlink')]",
                                "//*[contains(@class, 'wpulike')]",
                                "//*[contains(@class, 'wpulike-is-pro')]",
                                "//*[contains(@class, 'wpulike-updown-voting')]",
                                "//*[contains(@class, 'yarpp')]",
                                "//*[contains(@class, 'yarpp-related')]",
                                "//*[contains(@class, 'yarpp-related-website')]",
                                "//*[contains(@class, 'yarpp-template-list')]",
                                "//*[contains(@class, 'YEKTANET')]",
                                "//*[contains(@class, 'yektanet-content')]",
                                "//*[contains(@class, 'yn-article-display yn-borderbox')]",
                                "//*[contains(@class, 'yn-article-display')]",
                                "//*[contains(@id, 'breadcrumb')]",
                                "//*[contains(@id, 'comments')]",
                                "//*[contains(@id, 'dle-ajax-comments')]",
                                "//*[contains(@id, 'ez-toc-container')]",
                                "//*[contains(@id, 'inline-related-post')]",
                                "//*[contains(@id, 'pos-article-card-repeated')]",
                                "//*[contains(@id, 'post-extra-info')]",
                                "//*[contains(@id, 'related-posts')]",
                                "//*[contains(@id, 'review-box')]",
                                "//*[contains(@id, 'share-buttons-bottom')]",
                                "//*[contains(@id, 'side-relatedpost')]",
                                "//*[contains(@id, 'single-post-meta')]",
                                "//*[contains(@id, 'tab-1')]",
                                "//*[contains(@id, 'tab-2')]",
                                "//*[contains(@id, 'telegram')]",
                            );
    						foreach (glob(plugin_dir_path(__FILE__)."/sites/*.txt") as $klm) {
    							$fik = basename($klm, ".txt");
    							if ((!empty($fik)) && str_contains($url, $fik)) {
    								eval(file_get_contents($klm));
    								break;
    							}
    						}
    
    						foreach ($ignorel as $tynnj) {
    							if ($xpath->query($tynnj)->count() != false) {
    								$elements = $xpath->query($tynnj);
    								for ($gg = $elements->length; --$gg >= 0; ) {
    									$href = $elements->item($gg);
    									$href->parentNode->removeChild($href);
    								}
    							}
    						}
    						
    						
    						
    						//تعداد بیشترین عکس از هر سایت
    						$maximg = 5;
    						
    						
    						
    						$imgnum = 0;
    				
    						$cont = "";
    						foreach ($contents as $content) {
    							if ($xpath->query($content)->count() != false) {
								    $cont = preg_replace('/(<(script|style)\b[^>]*>).*?(<\/\2>)/s', "", $dom->saveHTML($xpath->query($content)->item(0)));
    								break;
    							}
    						}
    						if (empty($cont)) {
    						    echo "<br/>Cant Extract content!";
    							continue;
    						}
                        	global $imgnum, $maximg;
                        	$imgnum = 0;
                            $cont = preg_replace_callback('/<img[^>]+\>/i', function ($matches) {
                            	global $imgnum, $maximg;
                                $imgnum++;
                                if ($imgnum <= $maximg) {
                                     var_dump($imgnum);
                                  return $matches[0];
                                }
                                return "";
                            }, $cont);
    						$title = "---";
    						if ($xpath->query("//title")->count() != false) {
    							$title = $xpath->query("//title")->item(0)->nodeValue;
    						}
    
    
    						$confin["cont"][] = $cont;
    						$confin["url"][] = $url;
    						$confin["title"][] = $title;
							$ieee++;
    						$this->used_keyword=$link->keyword;
    						$kenfk = array();
    						foreach ($keywords as $kj) {
    							if (trim($kj) != $keyword)
    								$kenfk[] = $kj;
    						}
    						$query="update {$this->wp_prefix}automatic_camps set camp_keywords = '".implode(",", $kenfk)."' where camp_id  = '$camp->camp_id'";
    						$this->db->query($query);
						}
					} // foreach link
					if (!empty($confin["cont"])) {
						$ret = array();
						$ret ['cont'] = implode("<br/><hr/><br/>", $confin["cont"]);
						$ret ['title'] = implode(" - ", $confin["title"]);
						$ret ['original_title'] = $ret ['title'];
						$ret ['source_link'] = (implode(" - ", $confin["url"]));
						$ret ['author_name'] = "----";
						$ret ['author_link'] = "https://google.com";
						$ret ['matched_content'] = $ret ['cont'];
						return $ret;
					}

				} // if count(links)

			} // if keyword not ''
		} // foreach keyword
	}

}

