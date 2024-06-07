import { ref, computed } from "vue"

export default function useShowMore() {

    const showMore = ref(false)
    
    const getShowMoreContent = (content) => {
        if (!content) return ''

        if (showMore.value) return content
        
        return content?.length > 100 ? content.slice(0, 100) + '...' : content
    }

    function toggleShowMore() {
        showMore.value = !showMore.value
    }

    return {
        showMore, getShowMoreContent, toggleShowMore
    }
}