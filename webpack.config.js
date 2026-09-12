const path = require('path')
const webpack = require('webpack')
const { VueLoaderPlugin } = require('vue-loader')
const MiniCssExtractPlugin = require('mini-css-extract-plugin')

module.exports = {
	entry: {
		'migration-wizard': path.resolve(__dirname, 'src/main.js'),
		v2: path.resolve(__dirname, 'src-v2/main.js'),
	},
	output: {
		path: path.resolve(__dirname, 'js'),
		filename: 'souvera_mail-[name].js',
		chunkFilename: 'souvera_mail-[name]-[fullhash].js',
		publicPath: '/apps/souvera_mail/js/',
		clean: false,
		chunkLoadingGlobal: 'souveraMailChunks',
	},
	resolve: {
		extensions: ['.js', '.mjs', '.vue'],
		alias: {
			vue$: 'vue/dist/vue.runtime.esm-bundler.js',
		},
		fallback: {
			// @nextcloud/files → is-svg → @file-type/xml → sax declares node
			// 'stream'; it is never used at runtime by the toasts we import.
			stream: false,
		},
	},
	module: {
		rules: [
			// @nextcloud/vue ships .mjs as "fully specified" ESM — off it goes.
			{ test: /\.m?js$/, resolve: { fullySpecified: false } },
			{
				test: /\.vue$/,
				loader: 'vue-loader',
			},
			{
				test: /\.js$/,
				exclude: /node_modules\/(?!(@nextcloud|vue-material-design-icons)\/)/,
				use: {
					loader: 'babel-loader',
					options: {
						presets: [
							['@babel/preset-env', { targets: 'defaults' }],
						],
					},
				},
			},
			{
				test: /\.css$/,
				// CSS als eigene Datei extrahieren (style-loader injizierte
				// das CSS erst zur Laufzeit aus dem JS-Bundle → FOUC).
				use: [MiniCssExtractPlugin.loader, 'css-loader'],
			},
			{
				test: /\.scss$/,
				use: [MiniCssExtractPlugin.loader, 'css-loader', 'sass-loader'],
			},
		],
	},
	plugins: [
		new VueLoaderPlugin(),
		// CSS-Datei landet via ../ in css/ — der Server bindet sie server-
		// seitig per \OCP\Util::addStyle() im <head> ein (kein FOUC).
		// ignoreOrder: LimitChunkCountPlugin merged alle Chunks je Entry —
		// die Conflicting-Order-Warnungen sind damit obsolet.
		new MiniCssExtractPlugin({ filename: '../css/souvera_mail-[name].css', ignoreOrder: true }),
		// Merge ALL async chunks into the entry bundles — the deployment
		// (self-update zipball) has repeatedly failed to deliver chunk
		// files (404 → "Loading chunk N failed"), breaking the app.
		// One chunk per entry (migration-wizard + v2) = zero chunk files.
		new webpack.optimize.LimitChunkCountPlugin({ maxChunks: 2 }),
	],
	// Nextcloud globals (t, n, OC, OCP, OCA) are provided by the host page.
	// Vue must NOT be externalised — it is bundled per app.
	optimization: {
		splitChunks: false,
		// Disable dynamic-import chunk creation — bundle everything into main
		// entry point. Chunks that fail to deploy (404) break the entire app.
	},
	performance: {
		hints: false,
	},
}
