<?xml version="1.0" encoding="UTF-8"?>
<!--
XSL Stylesheet for Sitemap Urlset.

Renders XML sitemap urlset in a human-readable HTML format for browser viewing.

@package XWP\CustomXmlSitemap
-->
<xsl:stylesheet version="1.0"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9">

	<xsl:output method="html" encoding="UTF-8" indent="yes" />

	<xsl:template match="/">
		<html lang="en">
			<head>
				<meta charset="UTF-8" />
				<meta name="viewport" content="width=device-width, initial-scale=1.0" />
				<title>XML Sitemap</title>
				<style type="text/css">
					body {
						font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
						font-size: 14px;
						color: #333;
						margin: 0;
						padding: 20px;
						background: #f5f5f5;
					}
					.container {
						max-width: 1200px;
						margin: 0 auto;
						background: #fff;
						padding: 20px;
						border-radius: 4px;
						box-shadow: 0 1px 3px rgba(0,0,0,0.1);
					}
					h1 {
						margin: 0 0 20px;
						font-size: 24px;
						color: #1e1e1e;
					}
					p.info {
						margin: 0 0 20px;
						color: #666;
					}
					table {
						width: 100%;
						border-collapse: collapse;
					}
					th, td {
						padding: 10px 15px;
						text-align: left;
						border-bottom: 1px solid #eee;
					}
					th {
						background: #f8f8f8;
						font-weight: 600;
						color: #444;
					}
					tr:hover td {
						background: #fafafa;
					}
					a {
						color: #0073aa;
						text-decoration: none;
					}
					a:hover {
						text-decoration: underline;
					}
					.url-count {
						color: #666;
						font-size: 13px;
					}
				</style>
			</head>
			<body>
				<div class="container">
					<h1>XML Sitemap</h1>
					<p class="info">
						This sitemap contains <strong><xsl:value-of select="count(sitemap:urlset/sitemap:url)" /></strong> URLs.
					</p>
					<table>
						<thead>
							<tr>
								<th>URL</th>
								<th>Last Modified</th>
							</tr>
						</thead>
						<tbody>
							<xsl:for-each select="sitemap:urlset/sitemap:url">
								<tr>
									<td>
										<a href="{sitemap:loc}">
											<xsl:value-of select="sitemap:loc" />
										</a>
									</td>
									<td>
										<xsl:value-of select="sitemap:lastmod" />
									</td>
								</tr>
							</xsl:for-each>
						</tbody>
					</table>
				</div>
			</body>
		</html>
	</xsl:template>
</xsl:stylesheet>
