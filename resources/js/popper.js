import { createPopper } from '@popperjs/core';

export default {
  mounted(el, binding) {
    const referenceElement = document.getElementById(binding.value);
    if (referenceElement) {
      createPopper(referenceElement, el, {
        placement: 'auto', // Automatically choose the best placement
        modifiers: [
          {
            name: 'offset',
            options: {
              offset: [0, 8], // Offset the popper by 8px
            },
          },
        ],
      });
    }
  },
};