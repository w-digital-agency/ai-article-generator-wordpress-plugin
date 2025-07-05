# Auto Article Generator Pro - WordPress Plugin

<div align="center">

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![WordPress Plugin](https://img.shields.io/badge/wordpress-plugin-blue.svg)](https://wordpress.org)
[![Version](https://img.shields.io/badge/version-2.0-green.svg)](https://github.com/your-repo/auto-article-generator)

</div>

A powerful WordPress plugin that automatically generates high-quality, SEO-optimized articles using AI providers and synchronizes content from Notion databases.

## ✨ New Features in Version 2.0

### 🔄 Notion Synchronization
- **Direct Notion Integration**: Sync your Notion database pages directly to WordPress
- **Rich Content Support**: Images, videos, embeds, YouTube, Vimeo, and more
- **Automatic Conversion**: Notion blocks automatically convert to WordPress Gutenberg blocks
- **Real-time Sync**: Hourly automatic synchronization with manual sync option
- **Free Setup**: Uses Notion's free API - no additional costs

### 🤖 Simplified AI Providers
- **OpenRouter (Recommended)**: Access to 100+ AI models with free credits for beginners
- **OpenAI**: Direct integration with GPT models
- **Beginner-Friendly**: Start with free models, upgrade as needed
- **Cost-Effective**: OpenRouter provides competitive pricing across multiple providers

### 📝 Enhanced Content Generation
- **Improved Prompts**: Better SEO optimization and content structure
- **Multiple Languages**: English (US/UK), Chinese (Traditional/Simplified)
- **Writing Styles**: Informative, conversational, professional, casual, academic, persuasive
- **Gutenberg Ready**: Content automatically formatted for WordPress block editor

## 🚀 Quick Start Guide

### 1. Installation
1. Download the plugin zip file
2. Go to WordPress admin → Plugins → Add New
3. Upload and activate the plugin

### 2. AI Provider Setup (Choose One)

#### Option A: OpenRouter (Recommended for Beginners)
1. Visit [OpenRouter.ai](https://openrouter.ai/)
2. Sign up for a free account (get free credits!)
3. Go to [API Keys](https://openrouter.ai/keys) and create a new key
4. In WordPress: Article Generator Pro → AI Settings
5. Paste your OpenRouter API key
6. Select a free model like "Llama 3.1 8B (Free)"

#### Option B: OpenAI
1. Visit [OpenAI Platform](https://platform.openai.com/)
2. Create an account and add billing information
3. Go to [API Keys](https://platform.openai.com/api-keys)
4. Create a new API key
5. In WordPress: Article Generator Pro → AI Settings
6. Paste your OpenAI API key

### 3. Notion Sync Setup (Optional but Recommended)

#### Step 1: Create Notion Integration
1. Go to [Notion Integrations](https://www.notion.so/my-integrations)
2. Click "New integration"
3. Name it "WordPress Sync"
4. Copy the "Internal Integration Token"

#### Step 2: Prepare Your Database
1. Create a Notion database with these properties:
   - `Name` or `Title` (Title property)
   - `Status` (Select property with "Published" option)
2. Share the database with your integration:
   - Click "Share" → "Invite"
   - Search for your integration and invite it

#### Step 3: Configure Plugin
1. Go to Article Generator Pro → Notion Sync
2. Enable Notion Sync
3. Paste your Integration Token
4. Enter your Database ID (from the database URL)
5. Test the connection

## 📖 Usage

### Generate Articles with AI
1. Go to Article Generator Pro → Generate Content
2. Enter your target keyword and topic
3. Select language and writing style
4. Choose AI provider
5. Click "Generate Article"
6. Review and publish the draft

### Sync from Notion
1. Create content in your Notion database
2. Set status to "Published"
3. Content automatically syncs every hour
4. Or manually sync from Notion Sync tab

### Upload Markdown (Backup Method)
1. Go to Article Generator Pro → Markdown Upload
2. Select markdown files
3. Upload and convert to WordPress posts

## 🎯 Content Types Supported

### From Notion
- ✅ Text with rich formatting (bold, italic, links)
- ✅ Headings (H1, H2, H3)
- ✅ Lists (bulleted and numbered)
- ✅ Images (auto-imported to WordPress)
- ✅ Videos (YouTube, Vimeo, direct links)
- ✅ Embeds (any URL)
- ✅ Code blocks
- ✅ Quotes
- ✅ Dividers

### From AI Generation
- ✅ SEO-optimized content
- ✅ Proper heading structure
- ✅ Markdown formatting
- ✅ Multiple languages
- ✅ Various writing styles

## 🔧 Configuration Options

### AI Settings
- **Default Provider**: Choose between OpenRouter and OpenAI
- **Model Selection**: Pick the best model for your needs and budget
- **Request Timeout**: Adjust for longer content generation
- **Rate Limiting**: 10 generations per hour per user

### Image Settings
- **Quality Control**: Adjust JPEG/WebP compression (1-100)
- **Size Limits**: Set maximum image width
- **Alt Text**: Optional Google Vision API integration
- **Optimization**: Automatic image processing and resizing

### Notion Sync
- **Auto Sync**: Hourly automatic synchronization
- **Manual Sync**: On-demand synchronization
- **Status Tracking**: Monitor sync statistics
- **Error Handling**: Detailed error reporting

## 💰 Cost Comparison

### OpenRouter (Recommended)
- **Free Tier**: Free credits for testing
- **Pay-per-use**: Only pay for what you use
- **Model Variety**: Access to 100+ models
- **Transparent Pricing**: Clear cost per request

### OpenAI
- **No Free Tier**: Requires payment setup
- **Higher Costs**: Generally more expensive
- **Premium Quality**: Latest GPT models
- **Direct Access**: No intermediary

## 🛡️ Security Features

- **API Key Encryption**: All keys encrypted in database
- **Nonce Verification**: CSRF protection
- **Capability Checks**: Proper user permissions
- **Rate Limiting**: Prevents abuse
- **Secure File Operations**: Safe file handling
- **Security Logging**: Track all activities

## 🔄 Migration from Version 1.0

Version 2.0 is backward compatible:
- ✅ Existing settings preserved
- ✅ Old provider settings migrated
- ✅ Markdown upload still available
- ✅ All security features maintained

**Removed Providers**: Deepseek, Perplexity, and Grok are no longer directly supported. Use OpenRouter to access these models instead.

## 🆘 Troubleshooting

### Common Issues

**"API key not configured"**
- Ensure you've entered a valid API key
- Test the connection using the "Test Connection" button

**"Notion sync not working"**
- Verify your integration has access to the database
- Check that the database has required properties
- Ensure status is set to "Published"

**"Content not generating"**
- Check your API key balance/credits
- Verify internet connection
- Try increasing the request timeout

**"Images not importing"**
- Ensure WordPress has write permissions
- Check image URLs are accessible
- Verify image file sizes are reasonable

### Getting Help
1. Check the plugin settings for configuration issues
2. Review the security logs for error details
3. Test API connections using built-in tools
4. Contact support with specific error messages

## 🤝 Contributing

We welcome contributions! Please:
1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🔗 Useful Links

- [OpenRouter Models](https://openrouter.ai/models) - Browse available AI models
- [Notion API Documentation](https://developers.notion.com/) - Learn about Notion integration
- [WordPress Block Editor](https://wordpress.org/gutenberg/) - Understanding Gutenberg blocks
- [Plugin Support](https://github.com/your-repo/issues) - Get help and report issues

## 💝 Support the Project

If you find this plugin helpful:

<a href="https://www.buymeacoffee.com/wingleungblog">
  <img src="https://img.buymeacoffee.com/button-api/?text=Buy me a coffee&emoji=&slug=wingleungblog&button_colour=FFDD00&font_colour=000000&font_family=Cookie&outline_colour=000000&coffee_colour=ffffff" />
</a>

### Ways to Support
- ⭐ Star the repository
- 🐛 Report bugs and suggest features
- 🔧 Submit pull requests
- 📢 Share with others
- ☕ Support development via Buy Me a Coffee

---

<div align="center">
<strong>Auto Article Generator Pro v2.0</strong><br>
Making content creation effortless with AI and Notion integration
</div>