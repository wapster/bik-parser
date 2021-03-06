<?php
/*
Plugin Name: BIK parser
Plugin URI:
Description: Плагин парсинга cправочника БИК с сайта ЦБ РФ (http://www.cbr.ru/mcirabis/?Prtid=bic)
Version: 0.1
Author: r0ma
Author URI: http://r0ma.ru
License: Only for me
*/


// Регистрируем пользовательских тип поста - bik
add_action( 'init', 'create_bik_post_type' );
function create_bik_post_type() {
	$labels = array(
	'name' => 'БИК', // Основное название типа записи
	'singular_name' => 'БИК', // отдельное название записи типа Book
	'add_new' => 'Добавить БИК',
	'add_new_item' => 'Добавить новый БИК',
	'edit_item' => 'Редактировать БИК',
	'new_item' => 'Новый БИК',
	'view_item' => 'Посмотреть БИК',
	'search_items' => 'Найти БИК',
	'not_found' =>  'БИК не найден',
	'not_found_in_trash' => 'БИК в корзине не найден',
	'parent_item_colon' => '',
	'menu_name' => 'Все БИК'

  );
  $args = array(
	'labels' => $labels,
	'public' => true,
	'publicly_queryable' => true,
	'show_ui' => true,
	'show_in_menu' => true,
	'query_var' => true,
	'rewrite' => true,
	'capability_type' => 'post',
	'has_archive' => true,
	'hierarchical' => false,
	'taxonomies' => array('region'),
	'menu_position' => 101,
	'supports' => array('title','editor','author','thumbnail','excerpt','comments')
  );
  register_post_type('bik',$args);
}

// регистрируем таксономию
add_action('init', 'create_taxonomy');
function create_taxonomy() {
	// заголовки
	$labels = array(
		'name'              => 'region',
		'singular_name'     => 'Регион',
		'search_items'      => 'Поиск региона',
		'all_items'         => 'Все регионы',
		'parent_item'       => 'Parent Genre',
		'parent_item_colon' => 'Parent Genre:',
		'edit_item'         => 'Редактировать регион',
		'update_item'       => 'Обновить регион',
		'add_new_item'      => 'Добавить новый регион',
		'new_item_name'     => 'Имя нового региона',
		'menu_name'         => 'Регионы',
	);
	// параметры
	$args = array(
		'label'                 => '', // определяется параметром $labels->name
		'labels'                => $labels,
		'public'                => true,
		'publicly_queryable'    => null, // равен аргументу public
		'show_in_nav_menus'     => true, // равен аргументу public
		'show_ui'               => true, // равен аргументу public
		'show_tagcloud'         => true, // равен аргументу show_ui
		'hierarchical'          => false,
		'update_count_callback' => '',
		'rewrite'               => true,
		//'query_var'             => $taxonomy, // название параметра запроса
		'capabilities'          => array(),
		'meta_box_cb'           => null, // callback функция. Отвечает за html код метабокса (с версии 3.8): post_categories_meta_box или post_tags_meta_box. Если указать false, то метабокс будет отключен вообще
		'show_admin_column'     => false, // Позволить или нет авто-создание колонки таксономии в таблице ассоциированного типа записи. (с версии 3.5)
		'_builtin'              => false,
		'show_in_quick_edit'    => null, // по умолчанию значение show_ui
	);
	register_taxonomy('region', array('bik'), $args );
}


// Действия при активации плагина
register_activation_hook( __FILE__, 'bik_parser_install' );
function bik_parser_install() {

	// 1. СОЗДАЕМ СТРУКТУРУ ПАПОК ДЛЯ ХРАНЕНИЯ ДАННЫХ
	$upload = wp_upload_dir();
	$upload_dir = $upload['basedir'];

    // Создаем общую папку `bik` в wp-content/uploads
    $upload_dir = $upload_dir . '/bik';
    wp_mkdir_p( $upload_dir );

	// Создаем пустой файл index.php чтобы исключить просмотр директории
	file_put_contents( $upload_dir.'/index.php', '<?php // Silence is golden.' );

    // Создаем директорию для *dbf файлов
    $dbf_dir = $upload_dir . '/dbf';
    wp_mkdir_p( $dbf_dir );

    // Создаем директорию для архива
    $zip_dir = $upload_dir . '/zip';
    wp_mkdir_p( $zip_dir );

    // Создаем архивный файл
    $zip_file = $zip_dir.'/db_bik.zip';
    file_put_contents( $zip_file, '');


	// 2. СКАЧИВАЕМ И РАСПАКОВЫВАЕМ СВЕЖУЮ ВЕРСИЮ БАЗЫ ДАННЫХ С САЙТА ЦБ РФ

	date_default_timezone_set("Europe/Moscow");
	$date_in_link = date('dmY');

	// получаем индекс текущего дня недели (1-понедельник, 7 воскресенье)
	// и корректруем будущую ссылку (в выходные база не обновляется)
	$current_day = date( 'N' );
    if ( $current_day = 6) {
        $date_in_link = date('d')-1 . date('mY');
    }

    if ( $current_day = 7) {
        $date_in_link = date('d')-2 . date('mY');
    }

	$bik_db_link = 'http://www.cbr.ru/mcirabis/BIK/bik_db_' . $date_in_link . '.zip';
    $copy_zip_file = copy( $bik_db_link, $zip_file);

	if ( $copy_zip_file ) {
        $zip = new ZipArchive;
        $res = $zip->open( $zip_file );
        if ($res === TRUE) {
            $zip->extractTo( $dbf_dir );
            $zip->close();
        }
    }


	// 3. СОЗДАЕМ ТАБЛИЦЫ В БАЗЕ ДАННЫХ
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate(); // определяем кодировку

	/********** # 1 - BNKSEEK ********/
	$table_bnkseek = $wpdb->prefix . 'bik_bnkseek';
	$sql_bnkseek = "CREATE TABLE IF NOT EXISTS $table_bnkseek (
					VKEY		Char(8),
					VREAL       Char(4), /* оригинальное название поля REAL*/
					PZN         Char(2),
					UER         Char(1),
					RGN         Char(2),
					IND         Char(6),
					TNP         Char(1),
					NNP         Char(25),
					ADR         Char(30),
					RKC         Char(9),
					NAMEP       Char(45),
					NAMEN       Char(30),
					NEWNUM      Char(9),
					NEWKS       Char(9),
					PERMFO      Char(6),
					SROK        Char(2),
					AT1         Char(7),
					AT2         Char(7),
					TELEF       Char(25),
					REGN        Char(9),
					OKPO        Char(8),
					DT_IZM      DateTime,
					CKS         Char(6),
					KSNP        Char(20),
					DATE_IN     DateTime,
					DATE_CH		DateTime,
					VKEYDEL		Char(8),
					DT_IZMR		DateTime) ENGINE = InnoDB, $charset_collate";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql_bnkseek );

	/********** # 2 - REG ********/
	$table_reg = $wpdb->prefix . 'bik_reg';
	$sql_reg = "CREATE TABLE IF NOT EXISTS $table_reg (
					VKEY    Char(2),
					RGN     Char(2),
					NAME    Char(40),
					CENTER  Char(30),
					NAMET	Char(40)
					) ENGINE = InnoDB, $charset_collate";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql_reg );


	/********** # 3 - RCLOSE ********/
	$table_rclose = $wpdb->prefix . 'bik_rclose';
	$sql_rclose = "CREATE TABLE IF NOT EXISTS $table_rclose (
					VKEY		Char(2),
					R_CLOSE     Char(2),
					NAMECLOSE   Char(45)
					) ENGINE = InnoDB, $charset_collate";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql_rclose );



	/********** # 4 - REAL ********/
	$table_real = $wpdb->prefix . 'bik_real';
	$sql_real = "CREATE TABLE IF NOT EXISTS $table_real (
					VKEY		Char(4),
					VREAL       Char(4), /* оригинальное название поля REAL */
					NAME_OGR    Char(60)
					) ENGINE = InnoDB, $charset_collate";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql_real );



	// 4. КОНВЕРТИРУЕМ И ЗАГРУЖАЕМ ДАННЫЕ В ТАБЛИЦЫ (BNKSEEK, REG, ...))
	global $wpdb;

	$upload = wp_upload_dir();
	$upload_dir = $upload['basedir'];
	$dbf_dir = $upload_dir . '/bik/dbf/';

	// Выбираем нужные таблицы
	$data_base = array( 'bnkseek', 'reg', 'rclose', 'real' );

	foreach ($data_base as $table) {
		$db = dbase_open( $dbf_dir . $table . ".dbf", 0 );
		if ( $db ) {

			// Получаем имена колонок
			$colomns = dbase_get_header_info( $db );
			$colomns_name = array();

			foreach ($colomns as $key => $value) {
				if ( $value['name'] == 'REAL') {
					$value['name'] = 'VREAL';
				}
				$colomns_name[] = $value['name'];
			}

			// Кол-во записей в БД
			$x = dbase_numrecords( $db );

			for ($i=1; $i <= $x; $i++) {
				$rows = dbase_get_record_with_names( $db, $i );
				$a = array();
				foreach ($rows as $k => $v) {
					$v = iconv( "cp866", "utf-8", $v);
					$a[] = $v;
				}
				array_pop( $a ); // удаляем пустой элемент

				$combine = array_combine( $colomns_name, $a );

				// Делаем запись в БД !!!!
				// МАССИВ $combine передаем в качестве параметра
				// при вызове $wpdb->insert
				$wpdb->insert( $wpdb->prefix."bik_".$table, $combine );
			}

		dbase_close( $db );

		}

	}

	// 5. ЗАГРУЖАЕМ ДАННЫЕ В `WP_POSTS`(формируем посты)
	// --- запланированные записи (`post_status` = 'future')


	// ... ЗАПУСКАЕМ ПЛАНИРОВЩИК ПАРСИНГА СВЕЖЕЙ БАЗЫ С САЙТА ЦБ РФ.
}


// Создаем страницу настроек плагина
add_action( 'admin_menu', 'bik_parser_create_main_menu' );
function bik_parser_create_main_menu() {
	add_menu_page( "БИК парсер", "БИК парсер", 'manage_options', 'bik-parser-main-menu', 'bik_parser_mainmenu');
}




// Получаем полную информацию из таблицы
function getFullInfo( $title ) {
	global $wpdb;
	$table_bnkseek = $wpdb->prefix . 'bik_bnkseek';
	$sql = "SELECT * FROM wp_bik_bnkseek, wp_bik_real, wp_bik_reg WHERE wp_bik_bnkseek.NEWNUM = '$title' AND (wp_bik_bnkseek.RGN = wp_bik_reg.RGN) AND (wp_bik_bnkseek.VREAL = wp_bik_real.VREAL)";

	$get_info = $wpdb->get_row( $wpdb->prepare( $sql, null ) );

	return $get_info;
}



// Конвертируем дату из MySQL-формата в нужный нам формат
function convertDate( $date ) {
	$conv_date = mysql2date( 'd.m.Y', $date );
	echo $conv_date;
}



// Генерирует случайную дату увеличенную на `$count_day` дней
function random_date($count_day) {
	$now = date( 'Y-m-d H:i:s' );
	$date = date_create( $now );

	$days = "+$count_day day";
	$hours   = "+" . strval( rand( 1, 20) )  ." hours";
	$minutes = "+" . strval( rand( 10, 50) ) ." minutes";
	$seconds = "+" . strval( rand( 1, 59) ) ." seconds";

	date_modify( $date, "$hours $minutes $seconds $days" );
	$future_day = date_format( $date, 'Y-m-d H:i:s' );

	return $future_day;
}


// Получаем название региона по номеру БИК
function getRegion( $bik ) {
	global $wpdb;

	$table_bnkseek = $wpdb->prefix . 'bik_bnkseek';
	$table_reg = $wpdb->prefix . 'bik_reg';

	$sql = "SELECT $table_reg.NAME FROM $table_bnkseek, $table_reg WHERE ( $table_bnkseek.NEWNUM = '$bik' AND $table_bnkseek.RGN = $table_reg.RGN);";
	$get_region = $wpdb->get_row( $wpdb->prepare( $sql, null ) );

	return $get_region->NAME;
}


// Функция отображения страницы настроек
function bik_parser_mainmenu() {
	echo "<p>контент страницы настроек</p>";
	echo "
	<form action='' method='POST'>
		<!-- <input type='submit' name='add_dump' value='Обновить базу'> -->
		<p><input type='submit' name='testing' value='тестирование мелких скриптов'></p>
		<p><input type='submit' name='sync' value='Синхронизировать'></p>
	</form>";

	if ( !empty( $_POST['add_dump'] ) ) {
		global $wpdb;
		$sql = file_get_contents( $db_dir."/".$db_file[0] );
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
		echo "База успешно обновлена";
	}


	if ( !empty( $_POST['sync'] ) ) {


		// Получаем данные для работы
		global $wpdb;

		$table_bnkseek = $wpdb->prefix . 'bik_bnkseek';
		$arr_bnkseek = $wpdb->get_results( $wpdb->prepare( "SELECT `NEWNUM` FROM $table_bnkseek", null ), ARRAY_A );
		//немного форматируем массив для дальнейшей работы
		foreach ( $arr_bnkseek as $k) {
			$bnkseek[] = $k['NEWNUM'];
		}

		$table_posts = $wpdb->prefix . 'posts';
		$arr_posts = $wpdb->get_results( $wpdb->prepare( "SELECT `post_title` FROM $table_posts WHERE `post_type` = 'bik'", null ), ARRAY_A );
		//немного форматируем массив для дальнейшей работы
		foreach ( $arr_posts as $key) {
			$posts[] = $key['post_title'];
		}



		// Начинаем синхронизацию
		if ( (!empty($bnkseek)) AND (!empty($posts)) ) {

			$current_date = date( 'Y-m-d H:i:s' );

			// Синхронизируем BNKSEEK => WP_POSTS
			foreach ( $bnkseek as $bnk )  {


				if ( ! in_array( $bnk, $posts) ) {
					$post_data = array(
									'post_author' 		=> 1,
									'post_date'	  		=> $current_date,
									'post_date_gmt' 	=> $current_date,
									'post_title'  		=> $bnk,
									'post_status'		=> 'publish',
									'post_name'   		=> $bnk,
									'post_modified' 	=> $current_date,
									'post_modified_gmt' => $current_date,
									'post_type'   		=> 'bik'
								  );

					$post_id = wp_insert_post( wp_slash( $post_data ) );

					$region = getRegion( $bnk );
					wp_set_object_terms( $post_id, $region, 'region' );

					echo "БИК " .$bnk . " добавлен в таблицу wp_posts<br>";
				}

			}
			echo "<p><code><b>Добавить в БД нечего</b></code></p>";
		} //else echo "<pre>Исходная таблица $table_bnkseek пуста</pre>";



		if ( !empty($posts) AND (empty($bnkseek)) ) {
			$bnkseek= array();
			// Синхронизируем WP_POSTS => BNKSEEK
			foreach ( $posts as $bik )  {
				if ( ! in_array( $bik, $bnkseek) ) {
					global $wpdb;
					$tbl_posts = $wpdb->prefix . 'posts';
					$id_posts = $wpdb->get_row( $wpdb->prepare( "SELECT `ID` FROM $tbl_posts WHERE `post_title` = '$bik' ", null ) );
					$post_id = $id_posts->ID;
					wp_delete_post( $post_id );
					echo "<br>БИК $bik (id = $post_id) удален из wp_posts";
				}
			}
			echo "<p><code><b>Удалить из БД(wp_posts) нечего</b></code><p>";

		}



		if ( !empty($posts) ) {

			// Синхронизируем WP_POSTS => BNKSEEK
			foreach ( $posts as $bik )  {
				if ( ! in_array( $bik, $bnkseek) ) {
					global $wpdb;
					$tbl_posts = $wpdb->prefix . 'posts';
					$id_posts = $wpdb->get_row( $wpdb->prepare( "SELECT `ID` FROM $tbl_posts WHERE `post_title` = '$bik' ", null ) );
					$post_id = $id_posts->ID;
					wp_delete_post( $post_id );
					echo "<br>БИК $bik (id = $post_id) удален из wp_posts";
				}
			}
			echo "<p><code><b>Удалить из БД(wp_posts) нечего</b></code><p>";

		}


		if (empty($posts)) {

			echo "<pre><b>В таблице $table_posts нет записей post_type = 'bik'</b><br>";
			$posts = array();
			$current_date = date( 'Y-m-d H:i:s' );

			foreach ( $bnkseek as $bnk )  {

				if ( ! in_array( $bnk, $posts) ) {

					$post_data = array(
									'post_author' 		=> 1,
									'post_date'	  		=> $current_date,
									'post_date_gmt' 	=> $current_date,
									'post_title'  		=> $bnk,
									'post_status'		=> 'publish',
									'post_name'   		=> $bnk,
									'post_modified' 	=> $current_date,
									'post_modified_gmt' => $current_date,
									'post_type'   		=> 'bik'
								  );

					$post_id = wp_insert_post( wp_slash( $post_data ) );

					$region = getRegion( $bnk );
					wp_set_object_terms( $post_id, $region, 'region' );

					echo "БИК " .$bnk . " добавлен в таблицу wp_posts<br>";
				}
			}
			echo "<p><code><b>Добавить в БД нечего</b></code></p>";
			echo "</pre>";

		}


	} //end $_POST['sync']



	if ( !empty ($_POST['testing'] ) ) {
		global $wpdb;

		$data_base = array( 'bnkseek', 'rclose', 'real', 'reg');

		$upload = wp_upload_dir();
		$upload_dir = $upload['basedir'];

		$dbf_dir = $upload_dir . '/bik/dbf/';

		foreach ($data_base as $table) {
			$db = dbase_open( $dbf_dir . $table . ".dbf", 0 );
			if ( $db ) {

				// Получаем имена колонок
				$colomns = dbase_get_header_info( $db );
				$colomns_name = array();

				foreach ($colomns as $key => $value) {
					if ( $value['name'] == 'REAL') {
						$value['name'] = 'VREAL';
					}
					$colomns_name[] = $value['name'];
				}

				// Кол-во записей в БД
				$x = dbase_numrecords( $db );

				for ($i=1; $i <= $x; $i++) {
					$rows = dbase_get_record_with_names( $db, $i );
					$a = array();
					foreach ($rows as $k => $v) {
						$v = iconv( "cp866", "utf-8", $v);
						$a[] = $v;
					}
					array_pop( $a ); // удаляем пустой элемент

					$combine = array_combine( $colomns_name, $a );

					// Делаем запись в БД !!!!
					// МАССИВ $combine передаем в качестве параметра
					// при вызове $wpdb->insert
					$wpdb->insert( $wpdb->prefix."bik_".$table, $combine );
				}

			dbase_close( $db );

			}

		}


	}



}



// Действия при деактивации плагина
register_deactivation_hook( __FILE__, 'bik_parser_deactivate');
function bik_parser_deactivate() {
	// останавливаем плагировщик загрузки и распоковки zip-архива с сайта ЦБ РФ
	// останавливаем планировщик конвертации dbf -> sql и обновления таблиц
}

?>
