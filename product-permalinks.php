// Reserved slugs that must NEVER be treated as a product category prefix
function aakasi_reserved_slugs() {
    return array(
        'my-account', 'cart', 'checkout', 'shop', 'wp-admin', 'wp-content',
        'wp-includes', 'feed', 'product', 'wc-api', 'page', 'category', 'tag',
    );
}

// 1. Generate the actual product URL (but leave admin's editable slug box alone)
add_filter( 'post_type_link', 'aakasi_product_permalink', 10, 4 );
function aakasi_product_permalink( $permalink, $post, $leavename, $sample ) {
    if ( $post->post_type !== 'product' ) {
        return $permalink;
    }
    if ( $sample || $leavename ) {
        return $permalink;
    }
    $terms = get_the_terms( $post->ID, 'product_cat' );
    if ( ! $terms || is_wp_error( $terms ) ) {
        return $permalink;
    }
    $cat_slug = $terms[0]->slug;
    return home_url( '/' . $cat_slug . '/' . $post->post_name . '/' );
}

// 2. Get valid category slugs (excluding anything that collides with existing pages or reserved words)
function aakasi_valid_category_slugs() {
    $cats = get_terms( array(
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'fields'     => 'slugs',
    ) );
    if ( empty( $cats ) || is_wp_error( $cats ) ) {
        return array();
    }
    $reserved = aakasi_reserved_slugs();
    $valid = array();
    foreach ( $cats as $slug ) {
        if ( in_array( $slug, $reserved, true ) ) {
            continue;
        }
        if ( get_page_by_path( $slug ) ) {
            continue;
        }
        $valid[] = $slug;
    }
    return $valid;
}

// 3. Register rewrite rules for those category slugs only
add_action( 'init', 'aakasi_product_rewrite_rules' );
function aakasi_product_rewrite_rules() {
    $slugs = aakasi_valid_category_slugs();
    if ( empty( $slugs ) ) {
        return;
    }
    $pattern = implode( '|', array_map( 'preg_quote', $slugs ) );
    add_rewrite_rule(
        '^(' . $pattern . ')/([^/]+)/?$',
        'index.php?product=$matches[2]',
        'top'
    );
}

// 4. Auto-flush rewrite rules ONLY when the category list actually changes
add_action( 'init', 'aakasi_maybe_flush_rewrite_rules', 999 );
function aakasi_maybe_flush_rewrite_rules() {
    $current_slugs = aakasi_valid_category_slugs();
    sort( $current_slugs );
    $current_hash = md5( implode( ',', $current_slugs ) );
    $stored_hash = get_option( 'aakasi_cat_slugs_hash' );
    if ( $stored_hash !== $current_hash ) {
        flush_rewrite_rules();
        update_option( 'aakasi_cat_slugs_hash', $current_hash );
    }
}
