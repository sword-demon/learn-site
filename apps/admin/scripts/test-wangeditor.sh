#!/bin/bash
# apps/admin/scripts/test-wangeditor.sh
# WangEditor 集成验证脚本

set -e

echo "🔍 验证 WangEditor 集成..."
echo ""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if package.json exists
if [ ! -f "apps/admin/package.json" ]; then
    echo -e "${RED}❌ apps/admin/package.json not found${NC}"
    exit 1
fi

echo -e "${GREEN}✅ package.json exists${NC}"

# Check if ContentEditor.vue exists
if [ ! -f "apps/admin/src/components/course/ContentEditor.vue" ]; then
    echo -e "${RED}❌ ContentEditor.vue not found${NC}"
    exit 1
fi

echo -e "${GREEN}✅ ContentEditor.vue exists${NC}"

# Check if CourseEditView.vue imports ContentEditor
if grep -q "ContentEditor from '@/components/course/ContentEditor.vue'" apps/admin/src/views/catalog/CourseEditView.vue; then
    echo -e "${GREEN}✅ CourseEditView.vue correctly imports ContentEditor${NC}"
else
    echo -e "${YELLOW}⚠️  Warning: ContentEditor import not found in CourseEditView.vue${NC}"
fi

# Check if ContentEditor is used in template
if grep -q "<ContentEditor" apps/admin/src/views/catalog/CourseEditView.vue; then
    echo -e "${GREEN}✅ ContentEditor component is used in template${NC}"
else
    echo -e "${YELLOW}⚠️  Warning: ContentEditor component not found in template${NC}"
fi

# Check for required dependencies in admin package.json
ADMIN_PKG="apps/admin/package.json"

if grep -q '"@wangeditor/editor"' "$ADMIN_PKG"; then
    WANG_VERSION=$(grep '"@wangeditor/editor"' "$ADMIN_PKG" | cut -d'"' -f4)
    echo -e "${GREEN}✅ @wangeditor/editor installed: $WANG_VERSION${NC}"
else
    echo -e "${YELLOW}⚠️  @wangeditor/editor not found in dependencies${NC}"
    echo -e "${YELLOW}   To install, run:${NC}"
    echo -e "   ${BLUE}cd apps/admin && pnpm add @wangeditor/editor @wangeditor/editor-for-vue${NC}"
fi

if grep -q '"@wangeditor/editor-for-vue"' "$ADMIN_PKG"; then
    VUE_VERSION=$(grep '"@wangeditor/editor-for-vue"' "$ADMIN_PKG" | cut -d'"' -f4)
    echo -e "${GREEN}✅ @wangeditor/editor-for-vue installed: $VUE_VERSION${NC}"
else
    echo -e "${YELLOW}⚠️  @wangeditor/editor-for-vue not found in dependencies${NC}"
fi

# Check Node modules
if [ -d "apps/admin/node_modules/@wangeditor" ]; then
    echo -e "${GREEN}✅ WangEditor modules found in node_modules${NC}"
else
    echo -e "${YELLOW}⚠️  WangEditor modules not found (may need to run pnpm install)${NC}"
fi

# Syntax validation
echo ""
echo "📝 检查 Vue 文件语法..."

# Use Vue Language Tools if available
if command -v vue-tsc &> /dev/null; then
    cd apps/admin
    echo "Running vue-tsc on ContentEditor.vue..."
    if npx vue-tsc --noEmit src/components/course/ContentEditor.vue 2>/dev/null; then
        echo -e "${GREEN}✅ ContentEditor.vue passes TypeScript check${NC}"
    else
        echo -e "${YELLOW}⚠️  ContentEditor.vue has type errors${NC}"
    fi
    cd ../..
else
    echo -e "${YELLOW}⚠️  vue-tsc not found, skipping type check${NC}"
fi

# File encoding check
echo ""
echo "🔒 检查文件编码..."

# Detect file encoding with file command
ENCODING=$(file -b --mime-encoding apps/admin/src/components/course/ContentEditor.vue)
if [ "$ENCODING" = "utf-8" ]; then
    echo -e "${GREEN}✅ ContentEditor.vue is UTF-8 encoded${NC}"
else
    echo -e "${YELLOW}⚠️  Unexpected encoding: $ENCODING${NC}"
fi

# Summary
echo ""
echo "=========================================="
echo "📊 验证摘要"
echo "=========================================="

# Check final status
MISSING_DEPS=0
if ! grep -q '"@wangeditor/editor"' "$ADMIN_PKG"; then
    ((MISSING_DEPS++))
fi
if ! grep -q '"@wangeditor/editor-for-vue"' "$ADMIN_PKG"; then
    ((MISSING_DEPS++))
fi

if [ $MISSING_DEPS -gt 0 ]; then
    echo -e "${RED}❌ 缺少 $MISSING_DEPS 依赖项${NC}"
    echo ""
    echo "💡 请运行以下命令安装依赖:"
    echo ""
    echo -e "${BLUE}pnpm add @wangeditor/editor @wangeditor/editor-for-vue${NC}"
    echo ""
    echo "或者手动添加到 apps/admin/package.json"
else
    echo -e "${GREEN}✅ 所有依赖已安装!${NC}"
    echo ""
    echo "🎉 WangEditor 集成完成!"
    echo ""
    echo "下一步:"
    echo "1. 启动开发服务器：pnpm dev"
    echo "2. 访问课程管理页面：http://localhost:3000/admin/courses"
    echo "3. 创建或编辑课程并测试富文本编辑器"
fi

echo "=========================================="
