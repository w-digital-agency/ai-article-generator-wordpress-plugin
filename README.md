# AI Article Generator (AAG) for WordPress

<div align="center">

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![WordPress Plugin](https://img.shields.io/badge/wordpress-plugin-blue.svg)](https://wordpress.org)

</div>

A powerful WordPress plugin that automatically generates high-quality, SEO-optimized articles using various AI providers including Deepseek, Perplexity, Grok, and OpenRouter.

## ✨ Features

- 🤖 **Multiple AI Providers Support**
  - Deepseek
  - Perplexity
  - Grok
  - OpenRouter
  - Easy to extend with new providers

- 📝 **Content Generation**
  - SEO-optimized titles and content
  - Support for multiple writing styles
  - Markdown to WordPress conversion
  - Automatic image handling
  - Tag generation (with TaxoPress integration)

- 🌍 **Multilingual Support**
  - American English (en-US)
  - British English (en-GB)
  - Traditional Chinese (zh-TW)
  - Simplified Chinese (zh-CN)

- 🎨 **Writing Styles**
  - Informative
  - Conversational
  - Professional
  - Casual
  - Academic
  - Persuasive

- 🔒 **Security Features**
  - API key encryption
  - Nonce verification
  - Capability checks
  - Rate limiting
  - Secure file operations

## 🚀 Installation

1. Download the plugin zip file
2. Go to WordPress admin panel → Plugins → Add New
3. Click "Upload Plugin" and select the downloaded zip file
4. Click "Install Now" and then "Activate"

## ⚙️ Configuration

1. Go to Settings → Article Generator
2. Configure AI Providers:
   - Select default provider (Deepseek, Perplexity, Grok, OpenRouter)
   - Enter API keys for each provider
   - Configure provider-specific models:
     - Deepseek: Choose between Chat and Reasoner
     - Perplexity: Multiple model options available
     - OpenRouter: Custom model selection
3. Image Settings:
   - Set image quality (1-100)
   - Configure maximum image width
   - Set Vision API key for alt text generation
   - Customize image sizes
4. Configure request timeout (30-300 seconds)

## 🔧 Usage

### Generate Articles
1. Go to Article Generator in WordPress admin
2. Fill in the generation form:
   - Target Keyword (for SEO optimization)
   - Topic description
   - Select Language
   - Choose Writing Style
   - Pick AI Provider (or use default)
3. Click "Generate Article"

### Upload Markdown
1. Go to Article Generator in WordPress admin
2. Scroll to "Upload Markdown Files" section
3. Select one or more markdown files
4. Click "Upload Files"

## 🛡️ Rate Limiting

- Maximum 10 article generations per hour per user
- Helps prevent API abuse and ensures fair usage

## 🔌 Integration

### TaxoPress Integration
- Automatic tag generation when TaxoPress is active
- Uses WordPress term extraction API as fallback

## 🤝 Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🔧 Technical Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Valid API key for at least one supported AI provider
- Sufficient server memory for API operations
- Support for file uploads (for markdown functionality)

## 🔑 API Keys & Providers

- **API Keys Not Included**: This plugin requires API keys from the respective AI providers
- **Recommended for Beginners**: 
  - Start with [OpenRouter](https://openrouter.ai/) which offers:
    - Free API credits for testing
    - Access to multiple AI models
    - Simple signup process
- **Other Providers**:
  - [Deepseek](https://platform.deepseek.com/)
  - [Perplexity](https://www.perplexity.ai/)
  - [Grok](https://grok.x.ai/) (Requires X Premium+ subscription)

## 💝 Support the Project

If you find this plugin helpful, consider:

<a href="https://www.buymeacoffee.com/wingleungblog">
  <img src="https://img.buymeacoffee.com/button-api/?text=Buy me a coffee&emoji=&slug=wingleungblog&button_colour=FFDD00&font_colour=000000&font_family=Cookie&outline_colour=000000&coffee_colour=ffffff" />
</a>

Your support helps maintain and improve AAG!

### Ways to Contribute
- Star the repository
- Report bugs and suggest features
- Submit pull requests
- Share with others
- Support development via Buy Me a Coffee

## ⚠️ Important Notes

- Ensure your server has sufficient memory and execution time limits for API calls
- Keep your API keys secure
- Regular backups are recommended
- Monitor API usage to manage costs

## 🆘 Support

- Create an issue for bug reports
- Check documentation for common issues
- Contact support for urgent matters

---

<div align="center">
Made with ❤️ for WordPress content creators
</div> 