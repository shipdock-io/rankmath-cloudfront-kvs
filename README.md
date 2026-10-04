# rankmath-cloudfront-kvs

Rank Math redirects become extremely expensive for WordPress sites with many
thousands of entries. Exact match redirects can be offloaded to a CloudFront
viewer-request function, avoiding the origin altogether, and reducing the 
number of redirects that are loaded on each request.
