````markdown
# Woo Customer Segmentation

A WooCommerce customer segmentation plugin for WordPress that analyzes order behavior using **RFM+ features** and clusters customers into meaningful groups using **K-Means** or **DBSCAN**.

The plugin adds a segmentation dashboard directly inside the WooCommerce admin area, including charts, rankings, segment summaries, and CSV export.

## Features

- Customer clustering based on WooCommerce order history
- RFM+ style behavior analysis
- Configurable feature selection
- K-Means clustering
- DBSCAN clustering
- Optional feature normalization
- Segment labeling with business-friendly names
- Segment recommendations for marketing actions
- Dashboard charts and summaries
- Top categories and top products rankings
- CSV export of clustering results
- HPOS compatibility declaration
- No AJAX
- No `JSON.parse`
- Server-rendered admin UI

## What the plugin analyzes

The plugin builds customer segments from WooCommerce orders using these behavioral features:

- **Recency (days):** days since the customer’s last order
- **Frequency (orders):** number of orders in the selected window
- **Monetary:** total spend in the selected window
- **Average Order Value (AOV):** total spend divided by order count
- **Product Variety:** number of unique products purchased
- **Category Variety:** number of unique product categories purchased
- **Discount Reliance:** discount amount relative to subtotal

These features are used to build customer behavior vectors, which are then clustered into segments.

## Example segment labels

The plugin does not leave clusters as generic labels only. It maps them into more useful segment names such as:

- Loyal High-Value
- At-Risk High-Value
- At-Risk Deal-Driven
- At-Risk Variety-Seeker
- At-Risk Low-Engagement
- Discount-Driven
- Discount-Driven High-Value
- Frequent Low-Spend
- New / Emerging
- New High-AOV
- Core
- Core High-Value
- Core Low-Value
- Noise

Each segment also includes a suggested business action.

## Requirements

- WordPress
- WooCommerce
- PHP 7.4 or newer recommended
- Admin user with `manage_woocommerce` capability

## Installation

### Option 1: Install as a plugin folder

1. Copy the plugin folder into your WordPress plugins directory:

   `wp-content/plugins/woo-customer-segmentation`

2. Make sure the structure looks like this:

```text
woo-customer-segmentation/
├── woo-customer-segmentation.php
├── assets/
│   ├── admin.css
│   └── admin.js
└── includes/
    ├── class-wcs-plugin.php
    ├── class-wcs-admin-page.php
    ├── class-wcs-segmentation-service.php
    ├── class-wcs-data-extractor.php
    ├── class-wcs-clustering.php
    ├── class-wcs-settings.php
    └── class-wcs-utils.php
````

3. Activate the plugin in the WordPress admin area.
4. Go to **WooCommerce → Customer Segmentation**.

### Option 2: Upload ZIP

1. Zip the plugin folder.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP file.
4. Activate the plugin.

## Usage

After activation:

1. Open **WooCommerce → Customer Segmentation**
2. Configure the segmentation settings
3. Save the settings
4. Click **Run Clustering**
5. Review the results in the dashboard
6. Export CSV if needed

## Dashboard sections

### Control Panel

The control panel allows you to configure:

* Lookback window in days
* Algorithm
* K-Means cluster count
* K-Means max iterations
* DBSCAN epsilon
* DBSCAN minimum points
* Feature normalization
* Selected features

### Summary

Displays high-level KPI cards such as:

* Total customers clustered
* Top category
* Top product
* Behavior winner

### Charts

The dashboard includes:

* **Radar chart** for segment profiles
* **Bar chart** for segment sizes
* **Donut chart** for segment mix percentage

### Rankings

Shows:

* Top categories
* Top products
* Behavior leaders by feature

### Segments

Shows each segment with:

* Segment name
* Customer count
* Suggested marketing action
* Preview of member customers
* Top products for the segment
* Centroid values

### Export

Exports the latest segmentation run as CSV.

## Algorithms

### K-Means

K-Means is the default and recommended algorithm for larger datasets.

It works by:

1. Initializing cluster centroids
2. Assigning each customer to the nearest centroid
3. Recomputing centroids
4. Repeating until convergence or maximum iterations

#### Notes

* Uses Euclidean distance
* Random initialization is deterministic per run
* Better suited for larger customer datasets

### DBSCAN

DBSCAN groups customers based on density instead of a fixed number of clusters.

It is useful for:

* Finding dense customer behavior groups
* Identifying outliers
* Detecting irregular patterns

#### Notes

* More computationally expensive in PHP
* Better suited for smaller datasets
* Can produce noise points labeled as `-1`

## Safety limits

DBSCAN can be expensive for large datasets in pure PHP. To prevent slow or unstable runs, the plugin includes safeguards:

* DBSCAN lookback is capped at **50 days**
* DBSCAN is blocked for customer counts above **2500**
* The dashboard shows warnings when settings are likely to cause issues

If you need larger windows or larger customer counts, use **K-Means**.

## Normalization

When enabled, features are scaled to a 0–1 range before clustering.

This helps when features have very different numeric ranges, such as:

* Recency in days
* Spend in currency
* Order counts

### Special case: Recency inversion

Recency is inverted during normalization, so more recent customers receive a higher normalized score.

This makes clustering more intuitive, because stronger engagement maps to higher normalized values.

## Data source

The plugin reads WooCommerce orders directly using WooCommerce APIs.

### Included order statuses

* `wc-processing`
* `wc-completed`

### Customer identity logic

Customers are grouped by:

* WooCommerce customer ID when available
* Billing email for guest orders

### Data window

Only orders inside the configured lookback window are included.

## CSV export format

The CSV export includes:

* Customer key
* Customer ID
* Email
* Segment ID
* Segment name
* Raw feature values
* Normalized feature values, when normalization is enabled

This makes it easy to use the results in:

* Spreadsheets
* BI dashboards
* CRM imports
* Email marketing tools

## Project structure

### `woo-customer-segmentation.php`

Plugin bootstrap file. Loads dependencies and starts the plugin.

### `includes/class-wcs-plugin.php`

Registers WordPress and WooCommerce hooks.

### `includes/class-wcs-admin-page.php`

Handles admin menu registration, rendering, settings save, clustering runs, and CSV export.

### `includes/class-wcs-segmentation-service.php`

Contains the core segmentation workflow:

* Feature generation
* Normalization
* Clustering
* Segment naming
* Charts
* Rankings
* Export-ready payload creation

### `includes/class-wcs-data-extractor.php`

Extracts customer, product, category, and order data from WooCommerce.

### `includes/class-wcs-clustering.php`

Contains clustering algorithms:

* K-Means
* DBSCAN
* Centroid calculation

### `includes/class-wcs-settings.php`

Stores plugin constants, feature definitions, defaults, and setting sanitization.

### `includes/class-wcs-utils.php`

Contains shared helpers for:

* Money formatting
* Percentile calculations
* Normalization checks
* WooCommerce product/category label lookup

### `assets/admin.css`

Admin dashboard styles.

### `assets/admin.js`

Admin page tab switching and spinner behavior.

## Design choices

This plugin is intentionally built with a simpler admin architecture.

### No AJAX

All actions are server-side and form-driven.

### No `JSON.parse`

Chart data is emitted directly from PHP into JavaScript-safe literals.

### Server-rendered UI

This keeps the plugin lightweight and easier to follow for WordPress and PHP-based projects.

## Security

The plugin includes several standard WordPress security practices:

* Capability checks using `manage_woocommerce`
* Nonce validation for form actions
* Sanitization of admin input
* Restricted export endpoint
* Safe redirects
* Guard clause for direct file access

## HPOS compatibility

The plugin declares compatibility with WooCommerce High-Performance Order Storage using:

* `before_woocommerce_init`
* WooCommerce `FeaturesUtil::declare_compatibility()`

## Limitations

* DBSCAN is intentionally limited for performance reasons
* Clustering quality depends on the selected features and the quality of store order data
* Segment labels are heuristic and intended to be practical, not academically rigid
* Very small datasets may not produce useful segmentation
* Guest customers are grouped by billing email, so inconsistent emails may split the same customer across records

## Development notes

Common extension paths will include:

* Adding more customer features
* Adding filters for order statuses
* Adding date range presets
* Adding more export formats
* Adding segment history storage
* Adding custom chart styling
* Adding segment-specific campaign integrations

## Contributing

Contributions are welcome.

Typical contribution workflow:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test in WordPress and WooCommerce
5. Open a pull request

## License
GPL-3.0-or-later

## Repository description

WooCommerce customer segmentation plugin for WordPress that analyzes order behavior using RFM+ features and clustering algorithms like K-Means and DBSCAN, with an admin dashboard, charts, rankings, segment insights, and CSV export.
