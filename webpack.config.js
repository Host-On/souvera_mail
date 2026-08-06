const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')

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
				use: ['style-loader', 'css-loader'],
			},
			{
				test: /\.scss$/,
				use: ['style-loader', 'css-loader', 'sass-loader'],
			},
		],
	},
	plugins: [
		new VueLoaderPlugin(),
	],
	// Nextcloud globals (t, n, OC, OCP, OCA) are provided by the host page.
	// Vue must NOT be externalised — it is bundled per app.
	optimization: {
		splitChunks: false,
	},
	performance: {
		hints: false,
	},
}
