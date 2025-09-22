const path = require('path');
const TerserPlugin = require('terser-webpack-plugin');
const { CleanWebpackPlugin } = require('clean-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const { BundleAnalyzerPlugin } = require('webpack-bundle-analyzer');
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
  // Start from the default WP Scripts config and override where needed
  ...defaultConfig,

  // Add both entries: Google Drive page and Posts Maintenance page
  entry: {
    drivetestpage: './src/googledrive-page/main.jsx',
    postsmaintenance: './src/posts-maintenance/main.jsx',
  },

  output: {
    path: path.resolve(__dirname, 'assets/js'),
    filename: '[name].min.js',
    publicPath: '../../',
  },

  resolve: {
    // allow importing .js and .jsx without extension
    extensions: ['.js', '.jsx'],
  },

  module: {
    // Provide a clean set of rules that include babel-loader with JSX support
    rules: [
      {
        test: /\.(js|jsx)$/,
        exclude: /node_modules/,
        use: {
          loader: 'babel-loader',
          options: {
            // Ensure JSX is transformed
            presets: [
              '@babel/preset-env',
              '@babel/preset-react'
            ],
            cacheDirectory: true,
          },
        },
      },
      {
        test: /\.(css|scss)$/,
        use: [
          'style-loader',
          {
            loader: MiniCssExtractPlugin.loader,
            options: { esModule: false },
          },
          'css-loader',
          'sass-loader',
        ],
      },
      {
        test: /\.svg$/,
        type: 'asset/inline',
      },
      {
        test: /\.(png|jpg|gif)$/,
        type: 'asset/resource',
        generator: { filename: '../images/[name][ext][query]' },
      },
      {
        test: /\.(woff|woff2|eot|ttf|otf)$/,
        type: 'asset/resource',
        generator: { filename: '../fonts/[name][ext][query]' },
      },
    ],
  },

  // Tell webpack not to bundle WordPress-provided packages (use wp.* globals)
  externals: {
    '@wordpress/element': ['wp', 'element'],
    '@wordpress/components': ['wp', 'components'],
    '@wordpress/i18n': ['wp', 'i18n'],
    '@wordpress/hooks': ['wp', 'hooks'],
    '@wordpress/api-fetch': ['wp', 'apiFetch'],
  },

  plugins: [
    new CleanWebpackPlugin(),
    new MiniCssExtractPlugin({
      filename: '../css/[name].min.css',
    }),
    new BundleAnalyzerPlugin({
      analyzerMode: 'static',
      openAnalyzer: false,
      reportFilename: 'bundle-report.html',
    }),
    // keep any default plugins provided by @wordpress/scripts
    ...(defaultConfig.plugins || []),
  ],

  optimization: {
    minimize: true,
    minimizer: [
      new TerserPlugin({
        terserOptions: {
          format: { comments: false },
        },
        extractComments: false,
      }),
    ],
  },
};
